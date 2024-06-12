<?php

namespace Fieroo\Payment\Controllers;

use Illuminate\Http\Request;
use Fieroo\Bootstrapper\Controllers\BootstrapperController as Controller;
use Omnipay\Omnipay;
use Fieroo\Payment\Models\Payment;
use Fieroo\Events\Models\Event;
use Fieroo\Events\Models\CouponUser;
use Fieroo\Payment\Models\Order;
use Fieroo\Exhibitors\Models\Exhibitor;
use Fieroo\Bootstrapper\Models\User;
use Fieroo\Bootstrapper\Models\Setting;
use Fieroo\Stands\Models\StandsTypeTranslation;
use Validator;
use DB;
use Session;
use Mail;
use \Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;

class PaymentController extends Controller
{
    private $gateway;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        // $this->middleware('auth');
        $this->gateway = Omnipay::create('PayPal_Rest');
        $this->gateway->setClientId(env('PAYPAL_CLIENT_ID'));
        $this->gateway->setSecret(env('PAYPAL_CLIENT_SECRET'));
        $this->gateway->setTestMode(env('PAYPAL_TEST_MODE'));
    }

    public function pay(Request $request)
    {
        try {
            $validation_data = [
                'stand_selected' => ['required', 'exists:stands_types,id'],
                'modules_selected' => ['required', 'numeric', 'min:1'],
                'event_id' => ['required', 'exists:events,id'],
                'type_of_payment' => ['required', 'in:subscription,furnishing'],
            ];
    
            $validator = Validator::make($request->all(), $validation_data);
    
            if ($validator->fails()) {
                return redirect()
                    ->back()
                    ->withErrors(trans('generals.pay_validation_wrong'));
            }

            $exhibitor = auth()->user()->exhibitor;

            $stand = StandsTypeTranslation::where([
                ['stand_type_id', '=', $request->stand_selected],
                ['locale', '=', $exhibitor->locale]
            ])->firstOrFail();

            // $price = $stand->price;
            // $amount = $stand->price * $request->modules_selected;

            $setting = Setting::take(1)->first();

            $totalPrice = $stand->price * $request->modules_selected;

            // check if user has coupon assigned
            $userCoupon = CouponUser::where('user_id', auth()->user()->id)->first();
            if(is_object($userCoupon)) {
                $discount = $userCoupon->coupon->percentage;
                $discountAmount = ($discount/100)*$totalPrice;
                $totalPrice = $totalPrice - $discountAmount;
            }

            // Calculate tax and total
            $totalTax = $totalPrice/100 * $setting->iva;
            $totalTaxIncl = $totalPrice + $totalTax;

            $response = $this->gateway->purchase([
                'amount' => $totalTaxIncl,
                'currency' => env('PAYPAL_CURRENCY'),
                'returnUrl' => url('/admin/paypal/success'),
                'cancelUrl' => url('/admin/paypal/error'),
            ])->send();

            if($response->isRedirect()) {
                Session::put('purchase_data', [
                    'event_id' => $request->event_id,
                    'user_id' => auth()->user()->id,
                    'stand_type_id' => $request->stand_selected,
                    'n_modules' => $request->modules_selected,
                    'type_of_payment' => $request->type_of_payment
                ]);
                $response->redirect();
            } else {
                return redirect()
                    ->back()
                    ->withErrors($response->getMessage());
            }

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
    
            $validator = Validator::make($request->all(), $validation_data);
    
            if ($validator->fails()) {
                return redirect()
                    ->back()
                    ->withErrors(trans('generals.pay_validation_wrong'));
            }

            $authUser = auth()->user();
            $exhibitor = $authUser->exhibitor;

            $event = Event::findOrFail($request->event_id);

            $stand = StandsTypeTranslation::where([
                ['stand_type_id', '=', $request->stand_type_id],
                ['locale', '=', $exhibitor->locale]
            ])->firstOrFail();

            $rows = json_decode($request->data);
            $tot = 0;
            foreach($rows as $row) {
                $tot += $row->price;
            }

            $setting = Setting::take(1)->first();
            // Calculate tax and total
            $totalTax = $tot/100 * $setting->iva;
            $totalTaxIncl = $tot + $totalTax;

            if($tot > 0) {
                $response = $this->gateway->purchase([
                    'amount' => $totalTaxIncl,
                    'currency' => env('PAYPAL_CURRENCY'),
                    'returnUrl' => url('/admin/paypal/success-furnishings'),
                    'cancelUrl' => url('/admin/paypal/error'),
                ])->send();
    
                if($response->isRedirect()) {
                    Session::put('purchase_data', [
                        'event_id' => $request->event_id,
                        'user_id' => auth()->user()->id,
                        'exhibitor_id' => $exhibitor->id,
                        'stand_type_id' => $request->stand_type_id,
                        'type_of_payment' => $request->type_of_payment, // furnishing
                        'rows' => $rows,
                        'tot' => $totalTaxIncl
                    ]);
                    $response->redirect();
                } else {
                    return redirect()
                        ->back()
                        ->withErrors($response->getMessage());
                }
            } else {
                foreach($rows as $row) {
                    $this->insertOrder($exhibitor->id, $row, $request->event_id);
                }

                $purchase_data = [
                    'event_id' => $request->event_id,
                    'user_id' => auth()->user()->id,
                    'exhibitor_id' => $exhibitor->id,
                    'stand_type_id' => $request->stand_type_id,
                    'type_of_payment' => $request->type_of_payment, // furnishing
                    'rows' => $rows,
                    'tot' => $totalTaxIncl
                ];

                // $this->sendFurnishingEmails($exhibitor, $authUser, $exhibitor->locale, true, $tot, $request);
                $this->sendFurnishingEmails($request, $purchase_data, true);
                
                return redirect('admin/dashboard/')
                    ->with('success', trans('generals.payment_furnishing_ok', ['event' => $event->title]));
            }

        } catch(\Throwable $th){
            return redirect()
                ->back()
                ->withErrors($th->getMessage());
        }
    }

    public function success(Request $request)
    {
        if(!Session::has('purchase_data')) {
            abort(500, trans('generals.pay_validation_wrong'));
        }

        $purchase_data = Session::get('purchase_data');
        Session::forget('purchase_data');

        try {
            if($request->input('paymentId') && $request->input('PayerID')) {
                $transaction = $this->gateway->completePurchase([
                    'payer_id' => $request->input('PayerID'),
                    'transactionReference' => $request->input('paymentId'),
                ]);

                $response = $transaction->send();

                if($response->isSuccessful()) {
                    $arr = $response->getData();
                    $this->insertPayment($arr, $purchase_data);

                    // get the data
                    $event = Event::findOrFail($purchase_data['event_id']);
                    $user = User::findOrFail($purchase_data['user_id']);
                    $exhibitor = Exhibitor::where('user_id', '=', $user->id)->firstOrFail();

                    $subject = trans('emails.event_subscription', [], $exhibitor->locale);
                    $email_from = env('MAIL_FROM_ADDRESS');
                    $email_to = $user->email;
                    $setting = Setting::take(1)->first();
                    $emailTemplate = $exhibitor->locale == 'it' ? $setting->email_event_subscription_it : $setting->email_event_subscription_en;
                    $emailFormatData = [
                        'event_title' => $event->title,
                        'event_start' => Carbon::parse($event->start)->format('d/m/Y'),
                        'event_end' => Carbon::parse($event->end)->format('d/m/Y'),
                        'responsible' => $exhibitor->detail->responsible,
                    ];

                    $pdfName = 'subscription-confirmation.pdf';
                    $pdfContent = $this->generateOrderPDF($request, $purchase_data);
                    $this->sendEmail($subject, $emailFormatData, $emailTemplate, $email_from, $email_to, $pdfContent, $pdfName);

                    $email_admin = env('MAIL_ADMIN');
                    $this->sendEmail($subject, $emailFormatData, $emailTemplate, $email_from, $email_admin, $pdfContent, $pdfName);

                    return redirect('admin/dashboard/')
                        ->with('success', trans('generals.payment_subscription_ok', ['event' => $event->title]));
                } else {
                    return redirect('admin/events/'.$purchase_data['event_id'])
                        ->withErrors($response->getMessage());
                }
            } else {
                return redirect('admin/events/'.$purchase_data['event_id'])
                    ->withErrors(trans('generals.payment_declined'));
            }
        } catch(\Throwable $th) {
            return redirect('admin/events/'.$purchase_data['event_id'])
                ->withErrors($th->getMessage());
        }
    }

    public function successFurnishings(Request $request)
    {
        if(!Session::has('purchase_data')) {
            abort(500, trans('generals.pay_validation_wrong'));
        }

        $purchase_data = Session::get('purchase_data');
        Session::forget('purchase_data');

        try {
            if($request->input('paymentId') && $request->input('PayerID')) {
                $transaction = $this->gateway->completePurchase([
                    'payer_id' => $request->input('PayerID'),
                    'transactionReference' => $request->input('paymentId'),
                ]);

                $response = $transaction->send();

                if($response->isSuccessful()) {
                    $arr = $response->getData();
                    $payment = $this->insertPayment($arr, $purchase_data);

                    foreach($purchase_data['rows'] as $row) {
                        $this->insertOrder($purchase_data['exhibitor_id'], $row, $purchase_data['event_id'], $payment->id);
                    }

                    $event = Event::findOrFail($purchase_data['event_id']);
                    $authUser = auth()->user();
                    $exhibitor = $authUser->exhibitor;
    
                    $this->sendFurnishingEmails($request, $purchase_data, true);

                    // $this->sendFurnishingEmails($exhibitor, $authUser, $exhibitor->locale, true, $purchase_data['tot'], $request);

                    return redirect('admin/dashboard/')
                        ->with('success', trans('generals.payment_furnishing_ok', ['event' => $event->title]));
                } else {
                    return redirect('admin/dashboard/')
                        ->withErrors($response->getMessage());
                }
            } else {
                return redirect('admin/dashboard/')
                    ->withErrors(trans('generals.payment_declined'));
            }
        } catch(\Throwable $th) {
            return redirect('admin/dashbaard/')
                ->withErrors($th->getMessage());
        }
    }

    public function error()
    {
        return redirect('admin/dashboard/')
            ->withErrors(trans('generals.user_payment_declined'));
    }

    public function insertOrder($exhibitor_id, $row, $event_id, $paymentId = null)
    {
        return Order::create([
            'exhibitor_id' => $exhibitor_id,
            'furnishing_id' => $row->id,
            'qty' => $row->qty,
            'is_supplied' => $row->is_supplied,
            'price' => $row->price,
            'event_id' => $event_id,
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

    public function sendFurnishingEmails(Request $request, $purchase_data, $isToAdmin)
    {
        // Send email to exhibitor
        $user = User::findOrFail($purchase_data['user_id']);
        $locale = $user->exhibitor->locale;
        $subject = trans('emails.confirm_order', [], $locale);
        $email_from = env('MAIL_FROM_ADDRESS');
        $email_to = $user->email;

        $setting = Setting::take(1)->first();
        $emailTemplate = $locale == 'it' ? $setting->email_confirm_order_it : $setting->email_confirm_order_en;

        $labels = [
            'description' => trans('entities.furnishing', [], $locale),
            'color' => trans('tables.color', [], $locale),
            'qty' => trans('tables.qty', [], $locale),
            'price' => trans('tables.price', [], $locale),
        ];

        $orders = $this->getOrders($purchase_data['exhibitor_id'], $purchase_data['event_id'], null, $locale);
        $orders_txt = $this->generateOrderEmailSummary($orders, $labels);

        $emailFormatData = [
            'orders' => $orders_txt,
            'tot' => $purchase_data['tot'],
        ];

        $pdfName = 'order-confirmation.pdf';
        // $purchase_data = [
        //     'data' => $request->data,
        // ];
        $purchase_data['data'] = json_encode($purchase_data['rows']);
        
        $pdfContent = $this->generateOrderPDF($request, $purchase_data, 'order');

        $this->sendEmail($subject, $emailFormatData, $emailTemplate, $email_from, $email_to, $pdfContent, $pdfName);

        // Send email to admin if is needed
        if ($isToAdmin) {

            $admin_mail_subject = trans('emails.confirm_order', [], 'it');
            $admin_mail_email_to = env('MAIL_ADMIN');

            $emailTemplateAdmin = $setting->email_to_admin_notification_confirm_order;
            $ordersAdmin = $this->getOrders($purchase_data['exhibitor_id'], $purchase_data['event_id'], null, 'it');
            $orders_txt_admin = $this->generateOrderEmailSummary($ordersAdmin, $labels);

            $emailFormatDataAdmin = [
                'orders' => $orders_txt_admin,
                'tot' => $purchase_data['tot'],
                'company' => $user->exhibitor->detail->company,
            ];

            $this->sendEmail($admin_mail_subject, $emailFormatDataAdmin, $emailTemplateAdmin, $email_from, $admin_mail_email_to, $pdfContent, $pdfName);
        }
    }

    public function generateOrderPDF(Request $request, $purchase_data, string $typeOfPDF = 'subscription')
    {
        try {
            //Event and setting data
            $event = Event::findOrFail($purchase_data['event_id']);
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
                    ['stand_type_id', '=', $purchase_data['stand_type_id']],
                    ['locale', '=', $exhibitor->locale]
                ])->firstOrFail();
                $totalPrice = $stand->price * $purchase_data['n_modules'];

                // Calculate tax and total
                $totalTax = $totalPrice/100 * $setting->iva;
                $totalTaxIncl = $totalPrice + $totalTax;

                // Add specific data for the 'subscription' type
                $pdfView = view('payment::pdf.subscription-conf',  array_merge($commonData,  [
                    'totalPrice' => $totalPrice,
                    'totalTaxIncl' => $totalTaxIncl,
                    'totalTax' => $totalTax,
                    'stand' => $stand,
                    'qty' => $purchase_data['n_modules'],
                ]));
            } else {

                // Add specific data for the order case
                $rows = json_decode($purchase_data['data']);
                // $rows = json_decode($request->data);

                //Get total of items
                $tot = 0;
                foreach($rows as $row) {
                    $tot += $row->price;
                }

                // Calculate tax and total
                $totTax = $tot/100 * $setting->iva;
                $totTaxIncl = $tot + $totTax;

                $pdfView = view('payment::pdf.order-conf', array_merge($commonData, [
                    'orders' => $this->getOrders($exhibitor->id, $purchase_data['event_id'], null, $exhibitor->locale),
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

    public function insertPayment($arr, $purchase_data)
    {
        $payment = new Payment();
        $payment->payment_id = $arr['id'];
        $payment->payer_id = $arr['payer']['payer_info']['payer_id'];
        $payment->payer_email = $arr['payer']['payer_info']['email'];
        $payment->amount = $arr['transactions'][0]['amount']['total'];
        $payment->currency = env('PAYPAL_CURRENCY');
        $payment->payment_status = $arr['state'];
        $payment->event_id = $purchase_data['event_id'];
        $payment->user_id = $purchase_data['user_id'];
        $payment->stand_type_id = $purchase_data['stand_type_id'];
        if(isset($purchase_data['n_modules'])) {
            $payment->n_modules = $purchase_data['n_modules'];
        }
        $payment->type_of_payment = $purchase_data['type_of_payment'];
        $payment->save();
        return $payment;
    }
}
