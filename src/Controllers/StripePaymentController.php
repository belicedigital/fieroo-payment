<?php

namespace Fieroo\Payment\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Fieroo\Payment\Models\Payment;
use Fieroo\Events\Models\Event;
use Fieroo\Payment\Models\Order;
use Fieroo\Exhibitors\Models\Exhibitor;
use Fieroo\Bootstrapper\Models\Setting;
use Fieroo\Stands\Models\StandsTypeTranslation;
use Validator;
use DB;
use Mail;
use \Carbon\Carbon;

class StripePaymentController extends Controller
{
    public function payment(Request $request)
    {
        try {

            $exhibitor = auth()->user()->exhibitor;
            $event = Event::findOrFail($request->event_id)->first();

            $stand = StandsTypeTranslation::where([
                ['stand_type_id', '=', $request->stand_selected],
                ['locale', '=', $exhibitor->locale]
            ])->firstOrFail();

            $price = $stand->price * 100; // * 100 because stripe calc 1 => 0,01 cent
            $amount = $price * $request->modules_selected;
            $currency = env('CASHIER_CURRENCY');

            // if is not a customer is created
            $customer = $exhibitor->createOrGetStripeCustomer();
            $authUser = auth()->user();

            $totalPrice = $stand->price * $request->modules_selected;

            $validation_data = [
                'stand_selected' => ['required', 'exists:stands_types,id'],
                'modules_selected' => ['required', 'numeric', 'min:1']
            ];

            $validator = $this->validationData($validation_data, $request);

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
            ];

            $stripeCharge = $this->chargeStripe(
                $request->user(),
                $amount,
                $request->paymentMethodId,
                $customer,
                $stripeMetadata
            );

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
            $body = formatDataForEmail([
                'event_title' => $event->title,
                'event_start' => Carbon::parse($event->start)->format('d/m/Y'),
                'event_end' => Carbon::parse($event->end)->format('d/m/Y'),
                'responsible' => $exhibitor->detail->responsible,
            ], $emailTemplate);

            $data = [
                'body' => $body
            ];

           $this->sendEmail($subject, $data, $email_from, $email_to);

            return redirect('admin/dashboard/')
                ->with('success', trans('generals.payment_subscription_ok', ['event' => $event->title]));

        } catch(\Throwable $th){
            return redirect()
                ->back()
                ->withErrors($th->getMessage());
        }
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
            $event = Event::findOrFail($request->event_id)->first();
            $rows = json_decode($request->data);

            //Get total of items
            $tot = 0;
            foreach($rows as $row) {
                $tot += $row->price;
            }

            $validator = $this->validationData($validation_data, $request);

            if ($validator->fails()) {
                return redirect()
                    ->back()
                    ->withErrors(trans('generals.pay_validation_wrong'));
            }

            if($tot > 0) {
                $amount = $tot * 100; // * 100 per stripe
                $stripeMetadata = [
                    'type_of_payment' => 'furnishing_payment',
                ];

                //Stripe charge
                $stripeCharge = $this->chargeStripe($authUser, $amount, $request->paymentMethodId, $exhibitor, $stripeMetadata);

                //Get customer from Stripe
                $stripeCustomer = $exhibitor->asStripeCustomer();

                //insert payment into DB
                $payment = $this->insertPayment($stripeCharge, $stripeCustomer, $authUser, $request, $currency, $tot, $request->stand_type_id, null);

                //Update exhibitor payment data
                $this->updateExhibitor($exhibitor, $stripeCharge);

                foreach($rows as $row) {
                    $this->insertOrder($exhibitor, $row, $request, $payment, $payment->id);
                }

                //send email to exhibitor for furnishing order
                $orders = $this->getOrders($exhibitor->id, $payment->id, $exhibitor->locale);

                $labels = [
                    'description' => trans('entities.furnishing', [], $exhibitor->locale),
                    'color' => trans('tables.color', [],$exhibitor->locale),
                    'qty' => trans('tables.qty', [], $exhibitor->locale),
                    'price' => trans('tables.price', [], $exhibitor->locale),
                ];

                $orders_txt = '<dl>';
                foreach($orders as $order) {
                    foreach ($labels as $field => $label) {
                        $orders_txt .= '<dd>' . $label . ': ' . $order->$field . '</dd>';
                    }
                }
                $orders_txt .= '</dl>';

                $setting = Setting::take(1)->first();

                $emailTemplate = $exhibitor->locale == 'it' ? $setting->email_confirm_order_it : $setting->email_confirm_order_en;

                $body = formatDataForEmail([
                    'orders' => $orders_txt,
                    'tot' => $tot,
                ], $emailTemplate);

                $data = [
                    'body' => $body
                ];

                $subject = trans('emails.confirm_order', [], $exhibitor->locale);
                $email_from = env('MAIL_FROM_ADDRESS');
                $email_to = $authUser->email;

                $this->sendEmail($subject, $data, $email_from, $email_to);

                //Send email to admin for furnishing order
                $ordersAdmin = $this->getOrders($exhibitor->id, $payment->id, 'it');
                $emailTemplateAdmin = $setting->email_to_admin_notification_confirm_order;

                $orders_txt = '<dl>';
                foreach($ordersAdmin as $order) {
                    foreach ($labels as $field => $label) {
                        $orders_txt .= '<dd>' . $label . ': ' . $order->$field . '</dd>';
                    }
                }
                $orders_txt .= '</dl>';

                $body = formatDataForEmail([
                    'orders' => $orders_txt,
                    'tot' => $tot,
                    'company' => $exhibitor->detail->company,
                ], $emailTemplateAdmin);

                $data = [
                    'body' => $body
                ];

                $admin_mail_subject = trans('emails.confirm_order', [], 'it');
                $admin_mail_email_from = env('MAIL_FROM_ADDRESS');
                $admin_mail_email_to = env('MAIL_ARREDI');
                $this->sendEmail($admin_mail_subject, $data, $admin_mail_email_from, $admin_mail_email_to);

                return redirect('admin/dashboard/')
                    ->with('success', trans('generals.payment_subscription_ok', ['event' => $event->title]));
            } else {

                //insert order without payment
                foreach($rows as $row) {
                    $this->insertOrder($exhibitor, $row, $request, null, null);
                }

                //send email to exhibitor for furnishings order
                $setting = Setting::take(1)->firstOrFail();
                $orders = $this->getOrders($exhibitor->id, null, $exhibitor->locale);
                $emailTemplate = $exhibitor->locale == 'it' ? $setting->email_confirm_order_it : $setting->email_confirm_order_en;

                $labels = [
                    'description' => trans('entities.furnishing', [], $exhibitor->locale),
                    'color' => trans('tables.color', [],$exhibitor->locale),
                    'qty' => trans('tables.qty', [], $exhibitor->locale),
                    'price' => trans('tables.price', [], $exhibitor->locale),
                ];

                $orders_txt = '<dl>';
                foreach($orders as $order) {
                    foreach ($labels as $field => $label) {
                        $orders_txt .= '<dd>' . $label . ': ' . $order->$field . '</dd>';
                    }
                }
                $orders_txt .= '</dl>';

                $body = formatDataForEmail([
                    'orders' => $orders_txt,
                    'tot' => $tot,
                ], $emailTemplate);

                $data = [
                    'body' => $body
                ];

                $subject = trans('emails.confirm_order', [], $exhibitor->locale);
                $email_from = env('MAIL_FROM_ADDRESS');
                $email_to = $authUser->email;
                $this->sendEmail($subject, $data, $email_from, $email_to);

                //send email to admin for furnishings order without payment
                $ordersAdmin = $this->getOrders($exhibitor->id, null, 'it');
                $emailTemplateAdmin = $setting->email_to_admin_notification_confirm_order;

                $orders_txt = '<dl>';
                foreach($ordersAdmin as $order) {
                    foreach ($labels as $field => $label) {
                        $orders_txt .= '<dd>' . $label . ': ' . $order->$field . '</dd>';
                    }
                }
                $orders_txt .= '</dl>';

                $body = formatDataForEmail([
                    'orders' => $orders_txt,
                    'tot' => $tot,
                    'company' => $exhibitor->detail->company,
                ], $emailTemplateAdmin);

                $data = [
                    'body' => $body
                ];

                $admin_mail_subject = trans('emails.confirm_order', [], 'it');
                $admin_mail_email_from = env('MAIL_FROM_ADDRESS');
                $admin_mail_email_to = env('MAIL_ARREDI');
                $this->sendEmail($admin_mail_subject, $data, $admin_mail_email_from, $admin_mail_email_to);

                return redirect('admin/dashboard/')
                    ->with('success', trans('generals.payment_furnishing_ok', ['event' => $event->title]));
            }

        } catch(\Throwable $th){
            return redirect()
                ->back()
                ->withErrors($th->getMessage());
        }
    }

    public function validationData($validation_data, $request) {
        return Validator::make($request->all(), $validation_data);
    }

    public function updateStripeCustomerData($exhibitor) {

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

    public function updateExhibitor($exhibitor, $stripeCharge) {
        $updt_exhibitor = Exhibitor::findOrFail($exhibitor->id);
        $updt_exhibitor->pm_type = $stripeCharge->charges->data[0]->payment_method_details->type;
        $updt_exhibitor->pm_last_four = $stripeCharge->charges->data[0]->payment_method_details->card->last4;
        $updt_exhibitor->save();
    }

    public function sendEmail($subject, $data, $emailFrom, $emailTo) {
        Mail::send('emails.form-data', ['data' => $data], function ($m) use ($emailFrom, $emailTo, $subject) {
            $m->from($emailFrom, env('MAIL_FROM_NAME'));
            $m->to($emailTo)->subject(env('APP_NAME').' '.$subject);
        });
    }

    public function chargeStripe($authUser, $amount, $paymentMethodId, $customer, $metadata) {
        return $authUser->exhibitor->charge(
            $amount, $paymentMethodId, [
                'customer' => $customer->id,
                'receipt_email' => $authUser->email,
                'metadata' => $metadata,
            ]
        );
    }

    public function insertPayment($stripeCharge, $stripeCustomer, $authUser, $request, $currency, $totalPrice, $standID, $n_modules = null) {
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

    public function insertOrder($exhibitor, $row, $request, $payment = null, $paymentId = null)
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

    public function getOrders($exhibitorId, $paymentId, $exhibitorLocale)
    {
        $query = DB::table('orders')
            ->leftJoin('furnishings', 'orders.furnishing_id', '=', 'furnishings.id')
            ->leftJoin('furnishings_translations', function ($join) {
                $join->on('furnishings.id', '=', 'furnishings_translations.furnishing_id')
                    ->orOn('furnishings.variant_id', '=', 'furnishings_translations.furnishing_id');
            })
            ->where('orders.exhibitor_id', '=', $exhibitorId)
            ->where('furnishings_translations.locale', '=', $exhibitorLocale)
            ->select('orders.*', 'furnishings_translations.description', 'furnishings.color');

        if ($paymentId !== null) {
            $query->where('orders.payment_id', '=', $paymentId);
        }

        return $query->get();
    }

}
