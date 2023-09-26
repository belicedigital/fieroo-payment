<?php

namespace Fieroo\Payment\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
// use Illuminate\Support\Facades\App;
use Fieroo\Payment\Models\Payment;
use Fieroo\Events\Models\Event;
use Fieroo\Payment\Models\Order;
use Fieroo\Exhibitors\Models\Exhibitor;
use Fieroo\Bootstrapper\Models\Setting;
use Fieroo\Stands\Models\StandsTypeTranslation;
use Illuminate\Support\Facades\App;
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

            $validation_data = [
                'stand_selected' => ['required', 'exists:stands_types,id'],
                'modules_selected' => ['required', 'numeric', 'min:1']
            ];

            $validator = Validator::make($request->all(), $validation_data);
            $event = Event::findOrFail($request->event_id)->first();

            if ($validator->fails()) {
                return redirect()
                    ->back()
                    ->withErrors(trans('generals.pay_validation_wrong'));
            }

            $stand = StandsTypeTranslation::where([
                ['stand_type_id', '=', $request->stand_selected],
                ['locale', '=', $exhibitor->locale]
            ])->firstOrFail();

            $price = $stand->price * 100; // * 100 because stripe calc 1 => 0,01 cent
            $amount = $price * $request->modules_selected;

            // if is not a customer is created
            $customer = $exhibitor->createOrGetStripeCustomer();

            // updt customer data
            $exhibitor_detail = $exhibitor->detail;
            $data_for_billing = [
                'address' => [
                    'city' => $exhibitor_detail->city,
                    'postal_code' => $exhibitor_detail->cap,
                    'state' => $exhibitor_detail->province,
                    'line1' => $exhibitor_detail->address.', '.$exhibitor_detail->civic_number,
                ],
                'email' => $exhibitor->user->email,
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

            $totalPrice = $stand->price * $request->modules_selected;

            $stripeCharge = $request->user()->exhibitor->charge(
                $amount, $request->paymentMethodId, [
                    'customer' => $customer->id,
                    'receipt_email' => auth()->user()->email,
                    'metadata' => [
                        'type_of_payment' => 'event_subscription',
                        'stand_id' => $stand->stand_type_id,
                        'qty' => $request->modules_selected,
                        'single_price' => $stand->price,
                        'total_price' => $totalPrice,
                    ]
                ]
            );

            // Ottenere i dati del cliente da Stripe
            $stripeCustomer = $request->user()->exhibitor->asStripeCustomer();

            $payment = new Payment();
            $payment->payment_id = $stripeCharge->id; // ID della transazione Stripe
            $payment->payer_id = $stripeCustomer->id; // ID del cliente Stripe
            $payment->payer_email = auth()->user()->email; // Email dell'utente
            $payment->amount = $totalPrice; // Importo dell'ordine
            $payment->currency = env('CASHIER_CURRENCY'); // Valuta dell'ordine
            $payment->payment_status = 'succeeded'; // Stato del pagamento (puoi estrarlo da $stripeCharge)
            $payment->event_id = $request->event_id; // ID dell'evento correlato all'ordine
            $payment->user_id = auth()->user()->id; // ID dell'utente che ha effettuato il pagamento
            $payment->stand_type_id = $request->stand_selected; // ID del tipo di stand
            $payment->n_modules = $request->modules_selected; // Puoi impostare questo valore in base alle tue esigenze
            $payment->type_of_payment = $request->type_of_payment;
            $payment->save();

            $updt_exhibitor = Exhibitor::findOrFail($exhibitor->id);
            $updt_exhibitor->pm_type = $stripeCharge->charges->data[0]->payment_method_details->type;
            $updt_exhibitor->pm_last_four = $stripeCharge->charges->data[0]->payment_method_details->card->last4;
            $updt_exhibitor->save();

            // send email to user for subscription
            $email_from = env('MAIL_FROM_ADDRESS');
            $email_to = auth()->user()->email;
            $subject = trans('emails.event_subscription', [], $exhibitor->locale);
            $setting = Setting::take(1)->first();

            $body = formatDataForEmail([
                'event_title' => $event->title,
                'event_start' => Carbon::parse($event->start)->format('d/m/Y'),
                'event_end' => Carbon::parse($event->end)->format('d/m/Y'),
                'responsible' => $exhibitor->detail->responsible,
            ], $exhibitor->locale == 'it' ? $setting->email_event_subscription_it : $setting->email_event_subscription_en);

            $data = [
                'body' => $body
            ];

            Mail::send('emails.form-data', ['data' => $data], function ($m) use ($email_from, $email_to, $subject) {
                $m->from($email_from, env('MAIL_FROM_NAME'));
                $m->to($email_to)->subject(env('APP_NAME').' '.$subject);
            });

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
    
            $validator = Validator::make($request->all(), $validation_data);
    
            if ($validator->fails()) {
                return redirect()
                    ->back()
                    ->withErrors(trans('generals.pay_validation_wrong'));
            }

            $event = Event::findOrFail($request->event_id);

            $stand = StandsTypeTranslation::where([
                ['stand_type_id', '=', $request->stand_type_id],
                ['locale', '=', auth()->user()->exhibitor->locale]
            ])->firstOrFail();

            $rows = json_decode($request->data);
            $tot = 0;
            foreach($rows as $row) {
                $tot += $row->price;
            }

            if($tot > 0) {
                $amount = $tot * 100; // * 100 per stripe
                $stripeCharge = auth()->user()->exhibitor->charge(
                    $amount, $request->paymentMethodId, [
                        'customer' => auth()->user()->exhibitor->stripe_id,
                        'receipt_email' => auth()->user()->email,
                        'metadata' => [
                            'type_of_payment' => 'furnishing_payment',
                        ]
                    ]
                );

                // Ottenere i dati del cliente da Stripe
                $stripeCustomer = auth()->user()->exhibitor->asStripeCustomer();

                $payment = new Payment();
                $payment->payment_id = $stripeCharge->id; // ID della transazione Stripe
                $payment->payer_id = $stripeCustomer->id; // ID del cliente Stripe
                $payment->payer_email = auth()->user()->email; // Email dell'utente
                $payment->amount = $tot; // Importo dell'ordine
                $payment->currency = env('CASHIER_CURRENCY'); // Valuta dell'ordine
                $payment->payment_status = 'succeeded'; // Stato del pagamento (puoi estrarlo da $stripeCharge)
                $payment->event_id = $request->event_id; // ID dell'evento correlato all'ordine
                $payment->user_id = auth()->user()->id; // ID dell'utente che ha effettuato il pagamento
                $payment->stand_type_id = $request->stand_type_id; // ID del tipo di stand
                $payment->n_modules = null; // Puoi impostare questo valore in base alle tue esigenze
                $payment->type_of_payment = $request->type_of_payment;
                $payment->save();

                $updt_exhibitor = Exhibitor::findOrFail(auth()->user()->exhibitor->id);
                $updt_exhibitor->pm_type = $stripeCharge->charges->data[0]->payment_method_details->type;
                $updt_exhibitor->pm_last_four = $stripeCharge->charges->data[0]->payment_method_details->card->last4;
                $updt_exhibitor->save();

                foreach($rows as $row) {
                    Order::create([
                        'exhibitor_id' => auth()->user()->exhibitor->id,
                        'furnishing_id' => $row->id,
                        'qty' => $row->qty,
                        'is_supplied' => $row->is_supplied,
                        'price' => $row->price,
                        'event_id' => $request->event_id,
                        'payment_id' => $payment->id,
                        'created_at' => DB::raw('NOW()'),
                        'updated_at' => DB::raw('NOW()')
                    ]);
                }

                // send email to user for subscription
                $email_from = env('MAIL_FROM_ADDRESS');
                $email_to = auth()->user()->email;
                $subject = trans('emails.confirm_order', [], auth()->user()->exhibitor->locale);
                $setting = Setting::take(1)->firstOrFail();

                $body = formatDataForEmail([
                    'event_title' => $event->title,
                    'event_start' => Carbon::parse($event->start)->format('d/m/Y'),
                    'event_end' => Carbon::parse($event->end)->format('d/m/Y'),
                    'responsible' => auth()->user()->exhibitor->detail->responsible,
                ], auth()->user()->exhibitor->locale == 'it' ? $setting->email_confirm_order_it : $setting->email_confirm_order_en);

                $data = [
                    'body' => $body
                ];

                Mail::send('emails.form-data', ['data' => $data], function ($m) use ($email_from, $email_to, $subject) {
                    $m->from($email_from, env('MAIL_FROM_NAME'));
                    $m->to($email_to)->subject(env('APP_NAME').' '.$subject);
                });

                return redirect('admin/dashboard/')
                    ->with('success', trans('generals.payment_subscription_ok', ['event' => $event->title]));
            } else {
                foreach($rows as $row) {
                    Order::create([
                        'exhibitor_id' => auth()->user()->exhibitor->id,
                        'furnishing_id' => $row->id,
                        'qty' => $row->qty,
                        'is_supplied' => $row->is_supplied,
                        'price' => $row->price,
                        'event_id' => $request->event_id,
                        'payment_id' => null,
                        'created_at' => DB::raw('NOW()'),
                        'updated_at' => DB::raw('NOW()')
                    ]);
                }

                $orders = DB::table('orders')
                    ->leftJoin('furnishings', 'orders.furnishing_id', '=', 'furnishings.id')
                    ->leftJoin('furnishings_translations', function($join) {
                        $join->on('furnishings.id', '=', 'furnishings_translations.furnishing_id')
                            ->orOn('furnishings.variant_id', '=', 'furnishings_translations.furnishing_id');
                    })
                    ->where([
                        ['orders.exhibitor_id', '=', auth()->user()->exhibitor->id],
                        ['furnishings_translations.locale', '=', auth()->user()->exhibitor->locale]
                    ])
                    ->select('orders.*', 'furnishings_translations.description', 'furnishings.color')
                    ->get();

                $setting = Setting::take(1)->firstOrFail();

                $orders_txt = '<dl>';
                foreach($orders as $order) {
                    $orders_txt .= '<dd>'.trans('entities.furnishing', [], auth()->user()->exhibitor->locale).': '.$order->description.'</dd>';
                    $orders_txt .= '<dd>'.trans('tables.color', [], auth()->user()->exhibitor->locale).': '.$order->color.'</dd>';
                    $orders_txt .= '<dd>'.trans('tables.qty', [], auth()->user()->exhibitor->locale).': '.$order->qty.'</dd>';
                    $orders_txt .= '<dd>'.trans('tables.price', [], auth()->user()->exhibitor->locale).': '.$order->price.'</dd>';
                }
                $orders_txt .= '</dl>';
                
                $body = formatDataForEmail([
                    'orders' => $orders_txt,
                    'tot' => $tot,
                ], auth()->user()->exhibitor->locale == 'it' ? $setting->email_confirm_order_it : $setting->email_confirm_order_en);

                $data = [
                    'body' => $body
                ];

                $subject = trans('emails.confirm_order', [], auth()->user()->exhibitor->locale);
                $email_from = env('MAIL_FROM_ADDRESS');
                $email_to = auth()->user()->email;
                Mail::send('emails.form-data', ['data' => $data], function ($m) use ($email_from, $email_to, $subject) {
                    $m->from($email_from, env('MAIL_FROM_NAME'));
                    $m->to($email_to)->subject(env('APP_NAME').' '.$subject);
                });

                $orders = DB::table('orders')
                    ->leftJoin('furnishings', 'orders.furnishing_id', '=', 'furnishings.id')
                    ->leftJoin('furnishings_translations', function($join) {
                        $join->on('furnishings.id', '=', 'furnishings_translations.furnishing_id')
                            ->orOn('furnishings.variant_id', '=', 'furnishings_translations.furnishing_id');
                    })
                    ->where([
                        ['orders.exhibitor_id', '=', auth()->user()->exhibitor->id],
                        ['furnishings_translations.locale', '=', 'it']
                    ])
                    ->select('orders.*', 'furnishings_translations.description', 'furnishings.color')
                    ->get();

                $orders_txt = '<dl>';
                foreach($orders as $order) {
                    $orders_txt .= '<dd>Arredo: '.$order->description.'</dd>';
                    $orders_txt .= '<dd>Colore: '.$order->color.'</dd>';
                    $orders_txt .= '<dd>Quantità: '.$order->qty.'</dd>';
                    $orders_txt .= '<dd>Prezzo: '.$order->price.'</dd>';
                }
                $orders_txt .= '</dl>';

                $body = formatDataForEmail([
                    'orders' => $orders_txt,
                    'tot' => $tot,
                    'company' => auth()->user()->exhibitor->detail->company
                ], $setting->email_to_admin_notification_confirm_order);

                $data = [
                    'body' => $body
                ];

                $admin_mail_subject = trans('emails.confirm_order', [], 'it');
                $admin_mail_email_from = env('MAIL_FROM_ADDRESS');
                $admin_mail_email_to = env('MAIL_ARREDI');
                Mail::send('emails.form-data', ['data' => $data], function ($m) use ($admin_mail_email_from, $admin_mail_email_to, $admin_mail_subject) {
                    $m->from($admin_mail_email_from, env('MAIL_FROM_NAME'));
                    $m->to($admin_mail_email_to)->subject(env('APP_NAME').' '.$admin_mail_subject);
                });
                
                return redirect('admin/dashboard/')
                    ->with('success', trans('generals.payment_furnishing_ok', ['event' => $event->title]));
            }

        } catch(\Throwable $th){
            return redirect()
                ->back()
                ->withErrors($th->getMessage());
        }
    }
}