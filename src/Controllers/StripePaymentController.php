<?php

namespace Fieroo\Payment\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
// use Illuminate\Support\Facades\App;
use Fieroo\Payment\Models\Payment;
use Fieroo\Events\Models\Event;
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

            $stripeCharge = $request->user()->exhibitor->charge(
                $amount, $request->paymentMethodId, [
                    'customer' => $customer->id,
                    'receipt_email' => auth()->user()->email,
                    'metadata' => [
                        'type_of_payment' => 'event_subscription',
                        'stand_id' => $stand->stand_type_id,
                        'qty' => $request->modules_selected,
                        'single_price' => $stand->price,
                        'total_price' => $stand->price * $request->modules_selected,
                    ]
                ]
            );

            // $request->user()->exhibitor->invoicePrice(10, 5);
            // $request->user()->exhibitor->invoiceFor('prova', 1, [
            //     'price_data' => [
            //         'unit_amount'=> 1000,
            //         'currency' => 'eur',
            //         'product'=> [
            //           'name'=> 'prod_Ofot7Gv2iaQ07b'
            //         ],
            //         'tax_behavior'=>'exclusive',
        
            //     ]
            // ]);
            // invoiceFor fa anche il charge però sembra che non venga completato,  però almeno genera la fattura ma mancano le info  i tassi ed altro.
            $request->user()->exhibitor->invoiceFor('Stickers', 500, [
                'quantity' => 50,
            ], [
                'default_tax_rates' => ['tax-rate-id'],
            ]);

            $updt_exhibitor = Exhibitor::findOrFail($exhibitor->id);
            $updt_exhibitor->pm_type = $stripeCharge->charges->data[0]->payment_method_details->type;
            $updt_exhibitor->pm_last_four = $stripeCharge->charges->data[0]->payment_method_details->card->last4;
            $updt_exhibitor->save();

        } catch(\Throwable $th){
            return redirect()
                ->back()
                ->withErrors($th->getMessage());
        }
    }
}
