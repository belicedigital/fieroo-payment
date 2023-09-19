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

            $updt_exhibitor = Exhibitor::findOrFail($exhibitor->id);
            
            if(is_null($exhibitor->pm_type)) {
                $updt_exhibitor->pm_type = $stripeCharge->charges->data[0]->payment_method_details->type;
            }
            if(is_null($exhibitor->pm_last_four)) {
                $updt_exhibitor->pm_last_four = $stripeCharge->charges->data[0]->payment_method_details->card->last4;
            }

            $updt_exhibitor->save();

        } catch(\Throwable $th){
            return redirect()
                ->back()
                ->withErrors($th->getMessage());
        }
    }
}
