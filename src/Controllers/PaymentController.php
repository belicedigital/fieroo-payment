<?php

namespace Fieroo\Payment\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\App;
use Omnipay\Omnipay;
use Fieroo\Payment\Models\Payment;
use Fieroo\Events\Models\Event;
use Fieroo\Payment\Models\Order;
use Fieroo\Exhibitors\Models\Exhibitor;
use Fieroo\Bootstrapper\Models\User;
use Fieroo\Bootstrapper\Models\Setting;
use Validator;
use DB;
use Session;
use Mail;

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
        $this->gateway->setTestMode(true);
    }

    public function pay(Request $request)
    {
        try {
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

            $stand = DB::table('stands_types_translations')->where([
                ['stand_type_id', '=', $request->stand_selected],
                ['locale', '=', App::getLocale()]
            ])->first();

            if(!is_object($stand)) {
                return redirect()
                    ->back()
                    ->withErrors(trans('generals.pay_validation_wrong'));
            }

            $price = $stand->price;
            $amount = $stand->price * $request->modules_selected;

            $response = $this->gateway->purchase([
                'amount' => $amount,
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

            // $exhibitor = DB::table('exhibitors')->where('user_id','=',auth()->user()->id)->first();
            $exhibitor = Exhibitor::where('user_id','=',auth()->user()->id)->first();
            if(!is_object($exhibitor)) {
                return redirect()
                    ->back()
                    ->withErrors(trans('generals.pay_validation_wrong'));
            }

            $stand = DB::table('stands_types_translations')->where([
                ['stand_type_id', '=', $request->stand_type_id],
                ['locale', '=', App::getLocale()]
            ])->first();
            if(!is_object($stand)) {
                return redirect()
                    ->back()
                    ->withErrors(trans('generals.pay_validation_wrong'));
            }

            $rows = json_decode($request->data);
            $tot = 0;
            foreach($rows as $row) {
                $tot += $row->price;
            }

            if($tot > 0) {
                $response = $this->gateway->purchase([
                    'amount' => $tot,
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
                        'tot' => $tot
                    ]);
                    $response->redirect();
                } else {
                    return redirect()
                        ->back()
                        ->withErrors($response->getMessage());
                }
            } else {
                foreach($rows as $row) {
                    Order::create([
                        'exhibitor_id' => $exhibitor->id,
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

                $event = Event::findOrFail($request->event_id);

                $orders = DB::table('orders')
                    ->leftJoin('furnishings', 'orders.furnishing_id', '=', 'furnishings.id')
                    ->leftJoin('furnishings_translations', function($join) {
                        $join->on('furnishings.id', '=', 'furnishings_translations.furnishing_id')
                            ->orOn('furnishings.variant_id', '=', 'furnishings_translations.furnishing_id');
                    })
                    ->where([
                        ['orders.exhibitor_id', '=', $exhibitor->id],
                        ['furnishings_translations.locale', '=', $exhibitor->locale]
                    ])
                    ->select('orders.*', 'furnishings_translations.description', 'furnishings.color')
                    ->get();

                // $data = [
                //     'locale' => $exhibitor->locale,
                //     'orders' => $orders,
                //     'tot' => $tot
                // ];

                $setting = Setting::take(1)->first();

                $orders_txt = '<dl>';
                foreach($orders as $order) {
                    $orders_txt .= '<dd>'.trans('entities.furnishing', [], $exhibitor->locale).': '.$order->description.'</dd>';
                    $orders_txt .= '<dd>'.trans('tables.color', [], $exhibitor->locale).': '.$order->color.'</dd>';
                    $orders_txt .= '<dd>'.trans('tables.qty', [], $exhibitor->locale).': '.$order->qty.'</dd>';
                    $orders_txt .= '<dd>'.trans('tables.price', [], $exhibitor->locale).': '.$order->price.'</dd>';
                }
                $orders_txt .= '</dl>';
                
                $body = formatDataForEmail([
                    'orders' => $orders_txt,
                    'tot' => $tot,
                ], $exhibitor->locale == 'it' ? $setting->email_confirm_order_it : $setting->email_confirm_order_en);

                $data = [
                    'body' => $body
                ];

                $subject = trans('emails.confirm_order', [], $exhibitor->locale);
                $email_from = env('MAIL_FROM_ADDRESS');
                $email_to = auth()->user()->email;
                // Mail::send('emails.confirm-order', ['data' => $data], function ($m) use ($email_from, $email_to, $subject) {
                //     $m->from($email_from, env('MAIL_FROM_NAME'));
                //     $m->to($email_to)->subject(env('APP_NAME').' '.$subject);
                // });
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
                        ['orders.exhibitor_id', '=', $exhibitor->id],
                        ['furnishings_translations.locale', '=', 'it']
                    ])
                    ->select('orders.*', 'furnishings_translations.description', 'furnishings.color')
                    ->get();

                // $data = [
                //     'locale' => 'it',
                //     'orders' => $orders,
                //     'tot' => $tot
                // ];

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
                    'company' => $exhibitor->detail->company
                ], $setting->email_to_admin_notification_confirm_order);

                $data = [
                    'body' => $body
                ];

                $admin_mail_subject = trans('emails.confirm_order', [], 'it');
                $admin_mail_email_from = env('MAIL_FROM_ADDRESS');
                $admin_mail_email_to = env('MAIL_ARREDI');
                // Mail::send('emails.confirm-order', ['data' => $data], function ($m) use ($admin_mail_email_from, $admin_mail_email_to, $admin_mail_subject) {
                //     $m->from($admin_mail_email_from, env('MAIL_FROM_NAME'));
                //     $m->to($admin_mail_email_to)->subject(env('APP_NAME').' '.$admin_mail_subject);
                // });
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
                    $payment->n_modules = $purchase_data['n_modules'];
                    $payment->type_of_payment = $purchase_data['type_of_payment'];
                    $payment->save();

                    // get the data
                    $event = Event::findOrFail($purchase_data['event_id']);
                    $user = User::findOrFail($purchase_data['user_id']);
                    $exhibitor = Exhibitor::where('user_id', '=', $user->id)->firstOrFail();
                    // $exhibitor_data = DB::table('exhibitors_data')
                    //     ->where('exhibitor_id', '=', $exhibitor->id)
                    //     ->first();

                    // send email to user for subscription
                    $email_from = env('MAIL_FROM_ADDRESS');
                    $email_to = $user->email;
                    $subject = trans('emails.event_subscription', [], App::getLocale());
                    // $data = [
                    //     'locale' => App::getLocale(),
                    //     'event_title' => $event->title,
                    //     'event_start' => $event->start,
                    //     'event_end' => $event->end,
                    //     'responsible' => $exhibitor_data->responsible
                    // ];
                    $setting = Setting::take(1)->first();

                    $body = formatDataForEmail([
                        'event_title' => $event->title,
                        'event_start' => $event->start,
                        'event_end' => $event->end,
                        'responsible' => $exhibitor->detail->responsible,
                    ], $exhibitor->locale == 'it' ? $setting->email_event_subscription_it : $setting->email_event_subscription_en);

                    $data = [
                        'body' => $body
                    ];

                    // Mail::send('emails.event-subscription', ['data' => $data], function ($m) use ($email_from, $email_to, $subject) {
                    //     $m->from($email_from, env('MAIL_FROM_NAME'));
                    //     $m->to($email_to)->subject(env('APP_NAME').' '.$subject);
                    // });
                    Mail::send('emails.form-data', ['data' => $data], function ($m) use ($email_from, $email_to, $subject) {
                        $m->from($email_from, env('MAIL_FROM_NAME'));
                        $m->to($email_to)->subject(env('APP_NAME').' '.$subject);
                    });

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
                    $payment->n_modules = null;
                    $payment->type_of_payment = $purchase_data['type_of_payment'];
                    $payment->save();

                    foreach($purchase_data['rows'] as $row) {
                        Order::create([
                            'exhibitor_id' => $purchase_data['exhibitor_id'],
                            'furnishing_id' => $row->id,
                            'qty' => $row->qty,
                            'is_supplied' => $row->is_supplied,
                            'price' => $row->price,
                            'event_id' => $purchase_data['event_id'],
                            'payment_id' => $payment->id,
                            'created_at' => DB::raw('NOW()'),
                            'updated_at' => DB::raw('NOW()')
                        ]);
                    }

                    $event = Event::findOrFail($purchase_data['event_id']);
                    $exhibitor = Exhibitor::where('user_id', '=', auth()->user()->id)->firstOrFail();

                    $orders = DB::table('orders')
                        ->leftJoin('furnishings', 'orders.furnishing_id', '=', 'furnishings.id')
                        ->leftJoin('furnishings_translations', function($join) {
                            $join->on('furnishings.id', '=', 'furnishings_translations.furnishing_id')
                                ->orOn('furnishings.variant_id', '=', 'furnishings_translations.furnishing_id');
                        })
                        ->where([
                            ['orders.exhibitor_id', '=', $exhibitor->id],
                            ['furnishings_translations.locale', '=', $exhibitor->locale]
                        ])
                        ->select('orders.*', 'furnishings_translations.description', 'furnishings.color')
                        ->get();

                    // $data = [
                    //     'locale' => $exhibitor->locale,
                    //     'orders' => $orders,
                    //     'tot' => $purchase_data['tot']
                    // ];

                    $setting = Setting::take(1)->first();

                    $body = formatDataForEmail([
                        'orders' => $orders,
                        'tot' => $purchase_data['tot'],
                    ], $exhibitor->locale == 'it' ? $setting->email_confirm_order_it : $setting->email_confirm_order_en);

                    $data = [
                        'body' => $body
                    ];

                    $subject = trans('emails.confirm_order', [], $exhibitor->locale);
                    $email_from = env('MAIL_FROM_ADDRESS');
                    $email_to = auth()->user()->email;
                    // Mail::send('emails.confirm-order', ['data' => $data], function ($m) use ($email_from, $email_to, $subject) {
                    //     $m->from($email_from, env('MAIL_FROM_NAME'));
                    //     $m->to($email_to)->subject(env('APP_NAME').' '.$subject);
                    // });
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
                            ['orders.exhibitor_id', '=', $exhibitor->id],
                            ['furnishings_translations.locale', '=', 'it']
                        ])
                        ->select('orders.*', 'furnishings_translations.description', 'furnishings.color')
                        ->get();

                    // $data = [
                    //     'locale' => 'it',
                    //     'orders' => $orders,
                    //     'tot' => $purchase_data['tot']
                    // ];

                    $body = formatDataForEmail([
                        'orders' => $orders,
                        'tot' => $purchase_data['tot'],
                        'company' => $exhibitor->detail->company,
                    ], $setting->email_to_admin_notification_confirm_order);
    
                    $data = [
                        'body' => $body
                    ];

                    $admin_mail_subject = trans('emails.confirm_order', [], 'it');
                    $admin_mail_email_from = env('MAIL_FROM_ADDRESS');
                    $admin_mail_email_to = env('MAIL_ARREDI');
                    // Mail::send('emails.confirm-order', ['data' => $data], function ($m) use ($admin_mail_email_from, $admin_mail_email_to, $admin_mail_subject) {
                    //     $m->from($admin_mail_email_from, env('MAIL_FROM_NAME'));
                    //     $m->to($admin_mail_email_to)->subject(env('APP_NAME').' '.$admin_mail_subject);
                    // });
                    Mail::send('emails.form-data', ['data' => $data], function ($m) use ($admin_mail_email_from, $admin_mail_email_to, $admin_mail_subject) {
                        $m->from($admin_mail_email_from, env('MAIL_FROM_NAME'));
                        $m->to($admin_mail_email_to)->subject(env('APP_NAME').' '.$admin_mail_subject);
                    });

                    return redirect('admin/dashboard/')
                        ->with('success', trans('generals.payment_furnishing_ok', ['event' => $event->title]));
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

    public function error()
    {
        return redirect('admin/dashboard/')
            ->withErrors(trans('generals.user_payment_declined'));
    }
}
