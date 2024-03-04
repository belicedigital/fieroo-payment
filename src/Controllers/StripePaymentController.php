<?php

namespace Fieroo\Payment\Controllers;

use Illuminate\Http\Request;
use Fieroo\Bootstrapper\Controllers\BootstrapperController as Controller;
use Fieroo\Payment\Models\Payment;
use Fieroo\Events\Models\Event;
use Fieroo\Payment\Models\Order;
use Fieroo\Exhibitors\Models\Exhibitor;
use Fieroo\Bootstrapper\Models\Setting;
use Fieroo\Stands\Models\StandsTypeTranslation;
use Validator;
use DB;
use \Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Exceptions\IncompletePayment;

class StripePaymentController extends Controller
{
    public function payment(Request $request)
    {
        try {

            $exhibitor = auth()->user()->exhibitor;
            $event = Event::findOrFail($request->event_id);

            $stand = StandsTypeTranslation::where([
                ['stand_type_id', '=', $request->stand_selected],
                ['locale', '=', $exhibitor->locale]
            ])->firstOrFail();

            $price = $stand->price * 100; // * 100 because stripe calc 1 => 0,01 cent
            $amount = $price * $request->modules_selected;
            $amount_iva = $amount * 1.22;
            $currency = env('CASHIER_CURRENCY');

            // if is not a customer is created
            $customer = $exhibitor->createOrGetStripeCustomer();
            $authUser = auth()->user();

            $totalPrice = $stand->price * $request->modules_selected;
            $totalPrice_iva = $totalPrice * 1.22;

            $validation_data = [
                'stand_selected' => ['required', 'exists:stands_types,id'],
                'modules_selected' => ['required', 'numeric', 'min:1']
            ];

            $validator = Validator::make($request->all(), $validation_data);

            if ($validator->fails()) {
                return redirect()
                    ->back()
                    ->withErrors(trans('generals.pay_validation_wrong'));
            }

            // updt customer data
            $this->updateStripeCustomerData($exhibitor);

            //Charge with Stripe
            $stripeMetadata = [
                'type_of_payment' => 'event_subscription',
                'stand_id' => $stand->stand_type_id,
                'qty' => $request->modules_selected,
                'single_price' => $stand->price,
                'total_price' => $totalPrice,
                'totalPrice_iva' => $totalPrice_iva,
            ];

            $stripeCharge = $this->chargeStripe(
                $request->user(),
                $amount_iva,
                $request->paymentMethodId,
                $customer,
                $stripeMetadata
            );

            $this->compileDataStripeAndSendMail($request, $stripeCharge, $authUser, $currency, $totalPrice, $exhibitor);

            return redirect('admin/dashboard/')
                ->with('success', trans('generals.payment_subscription_ok', ['event' => $event->title]));

        } catch(IncompletePayment $exception) {
            $redirectRoute = route('compileDataStripeAndSendMail', [
                'request' => $request,
                'stripeCharge' => $stripeCharge,
                'authUser' => $authUser,
                'currency' => $currency,
                'totalPrice' => $totalPrice,
                'exhibitor' => $exhibitor,
            ]);
            return redirect()->route('cashier.payment', [$exception->payment->id, 'redirect' => $redirectRoute]);
        } catch(\Throwable $th){
            return redirect()
                ->back()
                ->withErrors($th->getMessage());
        }
    }

    public function compileDataStripeAndSendMail($request, $stripeCharge, $authUser, $currency, $totalPrice, $exhibitor)
    {
        // Ottenere i dati del cliente da Stripe
        $stripeCustomer = $request->user()->exhibitor->asStripeCustomer();

        //Insert payment in DB
        $this->insertPayment($stripeCharge, $stripeCustomer, $authUser, $request, $currency, $totalPrice, $request->stand_selected, $request->modules_selected);

        //Update Exhibitor payment data
        $this->updateExhibitor($exhibitor, $stripeCharge);

        // send email to user for subscription
        $subject = trans('emails.event_subscription', [], $exhibitor->locale);
        $email_from = env('MAIL_FROM_ADDRESS');
        $email_to = $authUser->email;
        $setting = Setting::take(1)->first();
        $emailTemplate = $exhibitor->locale == 'it' ? $setting->email_event_subscription_it : $setting->email_event_subscription_en;
        $emailFormatData = [
            'event_title' => $event->title,
            'event_start' => Carbon::parse($event->start)->format('d/m/Y'),
            'event_end' => Carbon::parse($event->end)->format('d/m/Y'),
            'responsible' => $exhibitor->detail->responsible,
        ];

        $pdfName = 'subscription-confirmation.pdf';
        $pdfContent = $this->generateOrderPDF($request);
        $this->sendEmail($subject, $emailFormatData, $emailTemplate, $email_from, $email_to, $pdfContent, $pdfName);

        $email_admin = env('MAIL_ADMIN');
        $this->sendEmail($subject, $emailFormatData, $emailTemplate, $email_from, $email_admin, $pdfContent, $pdfName);
    }

    public function auth3DSecure(Request $request)
    {
        $paymentIntentId = $request->payment_intent_id;

        // Effettua il reindirizzamento dell'utente alla pagina di autenticazione 3D Secure
        return view('payment::auth_3d_secure', compact('paymentIntentId'));
    }

    public function handle3DSecure(Request $request)
    {
        dd($request);
    }

    public function payFurnishings(Request $request)
    {
        try {
            $validation_data = [
                'stand_type_id' => ['required', 'exists:stands_types,id'],
                'event_id' => ['required', 'exists:events,id'],
                'type_of_payment' => ['required', 'string'],
                'data' => ['required', 'json']
            ];

            $authUser = auth()->user();
            $exhibitor = auth()->user()->exhibitor;
            $currency = env('CASHIER_CURRENCY');
            $event = Event::findOrFail($request->event_id);
            $rows = json_decode($request->data);

            //Get total of items
            $tot = 0;
            foreach($rows as $row) {
                $tot += $row->price;
            }

            $validator = Validator::make($request->all(), $validation_data);

            if ($validator->fails()) {
                return redirect()
                    ->back()
                    ->withErrors(trans('generals.pay_validation_wrong'));
            }

            $tot = $tot * 1.22;

            if($tot > 0) {
                $amount = $tot * 100; // * 100 per stripe
                $amount_iva = $amount * 1.22;
                $stripeMetadata = [
                    'type_of_payment' => 'furnishing_payment',
                ];

                //Stripe charge
                $stripeCharge = $this->chargeStripe($authUser, $amount_iva, $request->paymentMethodId, $exhibitor, $stripeMetadata);

                //Get customer from Stripe
                $stripeCustomer = $exhibitor->asStripeCustomer();

                //insert payment into DB
                $payment = $this->insertPayment($stripeCharge, $stripeCustomer, $authUser, $request, $currency, $tot, $request->stand_type_id, null);

                //Update exhibitor payment data
                $this->updateExhibitor($exhibitor, $stripeCharge);

                foreach($rows as $row) {
                    $this->insertOrder($exhibitor, $row, $request, $payment->id);
                }

            } else {
                //insert order without payment
                foreach($rows as $row) {
                    $this->insertOrder($exhibitor, $row, $request);
                }
            }

            $this->sendFurnishingEmails($exhibitor, $authUser, $exhibitor->locale, true, $tot, $request);

            return redirect('admin/dashboard/')
                ->with('success', trans('generals.payment_furnishing_ok', ['event' => $event->title]));

        } catch(\Throwable $th){
            return redirect()
                ->back()
                ->withErrors($th->getMessage());
        }
    }

    public function getExhibitorData($exhibitor) {
        $exhibitor_detail = $exhibitor->detail;

        $data_for_billing = [
            'address' => [
                'city' => $exhibitor_detail->city,
                'postal_code' => $exhibitor_detail->cap,
                'state' => $exhibitor_detail->province,
                'line1' => $exhibitor_detail->address.', '.$exhibitor_detail->civic_number,
            ],
            'email' => $exhibitor_detail->email_responsible,
            'name' => $exhibitor_detail->responsible,
            'phone' => $exhibitor_detail->phone_responsible,
            'preferred_locales' => [ $exhibitor->locale ],
            'company' => $exhibitor_detail->company,
            'vat_number' => $exhibitor_detail->vat_number
        ];

        if($exhibitor_detail->diff_billing) {
            $data_for_billing = [
                'address' => [
                    'city' => $exhibitor_detail->receiver_city,
                    'postal_code' => $exhibitor_detail->receiver_cap,
                    'state' => $exhibitor_detail->receiver_province,
                    'line1' => $exhibitor_detail->receiver_address.', '.$exhibitor_detail->receiver_civic_number,
                ],
                'email' => $exhibitor->user->email,
                'name' => $exhibitor_detail->responsible,
                'phone' => $exhibitor_detail->phone_responsible,
                'preferred_locales' => [ $exhibitor->locale ],
                'company' => $exhibitor_detail->company,
                'vat_number' => $exhibitor_detail->receiver_vat_number
            ];
        }

        return $data_for_billing;
    }

    public function updateStripeCustomerData($exhibitor)
    {

        $exhibitor_detail = $exhibitor->detail;

        $data_for_billing = [
            'address' => [
                'city' => $exhibitor_detail->city,
                'postal_code' => $exhibitor_detail->cap,
                'state' => $exhibitor_detail->province,
                'line1' => $exhibitor_detail->address.', '.$exhibitor_detail->civic_number,
            ],
            'email' => $exhibitor_detail->email_responsible,
            'name' => $exhibitor_detail->responsible,
            'phone' => $exhibitor_detail->phone_responsible,
            'preferred_locales' => [ $exhibitor->locale ],
        ];

        $vat_number = $exhibitor_detail->vat_number;

        if($exhibitor_detail->diff_billing) {
            $data_for_billing = [
                'address' => [
                    'city' => $exhibitor_detail->receiver_city,
                    'postal_code' => $exhibitor_detail->receiver_cap,
                    'state' => $exhibitor_detail->receiver_province,
                    'line1' => $exhibitor_detail->receiver_address.', '.$exhibitor_detail->receiver_civic_number,
                ],
                'email' => $exhibitor->user->email,
                'name' => $exhibitor_detail->responsible,
                'phone' => $exhibitor_detail->phone_responsible,
                'preferred_locales' => [ $exhibitor->locale ],
            ];
            $vat_number = $exhibitor_detail->receiver_vat_number;
        }

        $exhibitor->updateStripeCustomer($data_for_billing);

        if($exhibitor->taxIds()->count() <= 0) {
            $exhibitor->createTaxId('eu_vat', $vat_number);
        }
    }

    public function updateExhibitor($exhibitor, $stripeCharge)
    {
        $updt_exhibitor = Exhibitor::findOrFail($exhibitor->id);
        $updt_exhibitor->pm_type = $stripeCharge->charges->data[0]->payment_method_details->type;
        $updt_exhibitor->pm_last_four = $stripeCharge->charges->data[0]->payment_method_details->card->last4;
        $updt_exhibitor->save();
    }

    public function chargeStripe($authUser, $amount, $paymentMethodId, $customer, $metadata)
    {
        return $authUser->exhibitor->charge(
            $amount, $paymentMethodId, [
                'customer' => $customer->id,
                'receipt_email' => $authUser->email,
                'metadata' => $metadata,
            ]
        );
    }

    public function insertPayment($stripeCharge, $stripeCustomer, $authUser, $request, $currency, $totalPrice, $standID, $n_modules = null)
    {
        $payment = new Payment();
        $payment->payment_id = $stripeCharge->id;
        $payment->payer_id = $stripeCustomer->id;
        $payment->payer_email = $authUser->email;
        $payment->amount = $totalPrice;
        $payment->currency = $currency;
        $payment->payment_status = 'succeeded';
        $payment->event_id = $request->event_id;
        $payment->user_id = $authUser->id;
        $payment->stand_type_id = $standID;
        $payment->n_modules = $n_modules;
        $payment->type_of_payment = $request->type_of_payment;
        $payment->save();
        return $payment;
    }

    public function insertOrder($exhibitor, $row, $request, $paymentId = null)
    {
        return Order::create([
            'exhibitor_id' => $exhibitor->id,
            'furnishing_id' => $row->id,
            'qty' => $row->qty,
            'is_supplied' => $row->is_supplied,
            'price' => $row->price,
            'event_id' => $request->event_id,
            'payment_id' => $paymentId,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function getOrders($exhibitorId, $eventId, $paymentId, $exhibitorLocale)
    {
        $query = DB::table('orders')
            ->leftJoin('furnishings', 'orders.furnishing_id', '=', 'furnishings.id')
            ->leftJoin('furnishings_translations', function ($join) {
                $join->on('furnishings.id', '=', 'furnishings_translations.furnishing_id')
                    ->orOn('furnishings.variant_id', '=', 'furnishings_translations.furnishing_id');
            })
            ->where('orders.exhibitor_id', '=', $exhibitorId)
            ->where('orders.event_id','=', $eventId)
            ->where('furnishings_translations.locale', '=', $exhibitorLocale)
            ->select('orders.*', 'furnishings_translations.description', 'furnishings.color');

        if ($paymentId !== null) {
            $query->where('orders.payment_id', '=', $paymentId);
        }

        return $query->get();
    }

    public function generateOrderEmailSummary($orders, $labels)
    {
        $orders_txt = '<dl>';
        foreach ($orders as $order) {
            foreach ($labels as $field => $label) {
                $orders_txt .= '<dd>' . $label . ': ' . $order->$field . '</dd>';
            }
        }
        $orders_txt .= '</dl>';
        return $orders_txt;
    }

    public function sendFurnishingEmails($exhibitor, $authUser, $locale, $isToAdmin, $total, $request)
    {
        // Send email to exhibitor
        $subject = trans('emails.confirm_order', [], $locale);
        $email_from = env('MAIL_FROM_ADDRESS');
        $email_to = $authUser->email;

        $setting = Setting::take(1)->first();
        $emailTemplate = $locale == 'it' ? $setting->email_confirm_order_it : $setting->email_confirm_order_en;

        $labels = [
            'description' => trans('entities.furnishing', [], $locale),
            'color' => trans('tables.color', [], $locale),
            'qty' => trans('tables.qty', [], $locale),
            'price' => trans('tables.price', [], $locale),
        ];

        $orders = $this->getOrders($exhibitor->id, $request->event_id, null, $locale);
        $orders_txt = $this->generateOrderEmailSummary($orders, $labels);

        $emailFormatData = [
            'orders' => $orders_txt,
            'tot' => $total,
        ];

        $pdfName = 'order-confirmation.pdf';
        $pdfContent = $this->generateOrderPDF($request, 'order');

        $this->sendEmail($subject, $emailFormatData, $emailTemplate, $email_from, $email_to, $pdfContent, $pdfName);

        // Send email to admin if is needed
        if ($isToAdmin) {

            $admin_mail_subject = trans('emails.confirm_order', [], 'it');
            $admin_mail_email_to = env('MAIL_ADMIN');

            $emailTemplateAdmin = $setting->email_to_admin_notification_confirm_order;
            $ordersAdmin = $this->getOrders($exhibitor->id, $request->event_id, null, 'it');
            $orders_txt_admin = $this->generateOrderEmailSummary($ordersAdmin, $labels);

            $emailFormatDataAdmin = [
                'orders' => $orders_txt_admin,
                'tot' => $total,
                'company' => $exhibitor->detail->company,
            ];

            $this->sendEmail($admin_mail_subject, $emailFormatDataAdmin, $emailTemplateAdmin, $email_from, $admin_mail_email_to, $pdfContent, $pdfName);
        }
    }

    public function generateOrderPDF(Request $request, string $typeOfPDF = 'subscription')
    {
        try {
            //Event and setting data
            $event = Event::findOrFail($request->event_id);
            $setting = Setting::take(1)->first();

            // Create a DOMPDF object
            $pdfOptions = new Options();
            $pdfOptions->set('isHtml5ParserEnabled', true);
            $pdfOptions->set('isPhpEnabled', true);
            $pdfOptions->set('isRemoteEnabled', true);
            $pdfOptions->set('defaultMediaType', 'all');
            $pdfOptions->setDefaultFont('dejavu sans');
            $pdf = new Dompdf($pdfOptions);

            // Exhibitor data
            $exhibitor = auth()->user()->exhibitor;
            $data_for_billing = $this->getExhibitorData($exhibitor);

            // Create a common set of data
            $commonData = [
                'event' => $event,
                'iva' => $setting->iva,
                'exhibitor' => $data_for_billing,
                'paymentId' => $request->paymentMethodId
            ];

            if ($typeOfPDF == 'subscription') {

                //Stand data for subscription event
                $stand = StandsTypeTranslation::where([
                    ['stand_type_id', '=', $request->stand_selected],
                    ['locale', '=', $exhibitor->locale]
                ])->firstOrFail();
                $totalPrice = $stand->price * $request->modules_selected;

                // Calculate tax and total
                $totalTax = $totalPrice/100 * $setting->iva;
                $totalTaxIncl = $totalPrice + $totalTax;

                // Add specific data for the 'subscription' type
                $pdfView = view('payment::pdf.subscription-conf',  array_merge($commonData,  [
                    'totalPrice' => $totalPrice,
                    'totalTaxIncl' => $totalTaxIncl,
                    'totalTax' => $totalTax,
                    'stand' => $stand,
                    'qty' => $request->modules_selected,
                ]));
            } else {

                // Add specific data for the order case
                $rows = json_decode($request->data);

                //Get total of items
                $tot = 0;
                foreach($rows as $row) {
                    $tot += $row->price;
                }

                // Calculate tax and total
                $totTax = $tot/100 * $setting->iva;
                $totTaxIncl = $tot + $totTax;

                $pdfView = view('payment::pdf.order-conf', array_merge($commonData, [
                    'orders' => $this->getOrders($exhibitor->id, $request->event_id, null, $exhibitor->locale),
                    'ordersTot' => $tot,
                    'ordersTotTaxIncl' => $totTaxIncl,
                    'totTax' => $totTax
                ]));
            }

            // Convert to Html
            $html = $pdfView->render();

            // Load HTML in Dompdf
            $pdf->loadHtml($html);

            // Set rendering option
            $pdf->setPaper('A4');

            // Render PDF
            $pdf->render();

            return $pdf->output();

        } catch (\Throwable $th) {
            return redirect()
                ->back()
                ->withErrors($th->getMessage());
        }
    }
}
