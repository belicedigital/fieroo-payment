@extends('layouts.pdf')
@section('content')
    <div class="pdf-template-content">
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td align="center" style="background-color: #eeeeee;" bgcolor="#eeeeee">

                <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;">
                    @include('partials.email-header')
                    <tr>
                        <td align="center" style="padding: 35px 35px 20px 35px; background-color: #ffffff;" bgcolor="#ffffff">
                            <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;">
                                <tr>
                                    <td align="center" style="font-size: 16px; font-weight: 400; line-height: 24px; padding-top: 25px;">
                                        <h2 style="font-size: 24px; font-weight: 800; line-height: 36px; color: #333333; margin: 0;">
                                            {{ trans('generals.thank_you') }} {{ $exhibitor['name'] }}!<br>{{ trans('generals.order') }} {{ $paymentId }}
                                        </h2>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="left" style="font-size: 16px; font-weight: 400; line-height: 24px; padding-top: 10px;">
                                        <p style="font-size: 16px; font-weight: 400; line-height: 24px; color: #777777;">
                                            {{ trans('generals.info_order') }}
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="left" style="padding-top: 20px;">
                                        <table cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td width="45%" align="left" bgcolor="#eeeeee" style="font-size: 16px; font-weight: 800; line-height: 24px; padding: 10px;">
                                                    {{ trans('generals.description') }}
                                                </td>
                                                <td width="10%" align="left" bgcolor="#eeeeee" style="font-size: 16px; font-weight: 800; line-height: 24px; padding: 10px;">
                                                    {{ trans('generals.qty') }}
                                                </td>
                                                <td width="22%" align="left" bgcolor="#eeeeee" style="font-size: 16px; font-weight: 800; line-height: 24px; padding: 10px;">
                                                    {{ trans('generals.subtotal') }}
                                                </td>
                                                <td width="22%" align="left" bgcolor="#eeeeee" style="font-size: 16px; font-weight: 800; line-height: 24px; padding: 10px;">
                                                    {{ trans('generals.total') }}
                                                </td>
                                            </tr>
                                            @foreach($orders as $order)
                                                <tr>
                                                    <td width="45%" align="left" style="font-size: 16px; font-weight: 400; line-height: 24px; padding: 15px 10px 5px 10px;">
                                                        {{ $order->description }}<br><small>{{ $order->color }}</small>
                                                    </td>
                                                    <td width="10%" align="left" style="font-size: 16px; font-weight: 400; line-height: 24px; padding: 15px 10px 5px 10px;">
                                                        {{ $order->qty }}
                                                    </td>
                                                    <td width="22%" align="left" style="font-size: 16px; font-weight: 400; line-height: 24px; padding: 15px 10px 5px 10px;">
                                                        {{ $order->price }} €
                                                    </td>
                                                    <td width="22%" align="left" style="font-size: 16px; font-weight: 400; line-height: 24px; padding: 15px 10px 5px 10px;">
                                                        {{ $order->price * $order->qty }} €
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="left" style="padding-top: 20px;">
                                        <table cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td width="78%" align="left" style="font-size: 16px; font-weight: 800; line-height: 24px; padding: 10px; border-top: 3px solid #eeeeee; border-bottom: 3px solid #eeeeee;">
                                                    {{ trans('generals.tax') }} ({{ $iva }}%)
                                                </td>
                                                <td width="22%" align="left" style="font-size: 16px; font-weight: 800; line-height: 24px; padding: 10px; border-top: 3px solid #eeeeee; border-bottom: 3px solid #eeeeee;">
                                                    {{ $totTax }} €
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="78%" align="left" style="font-size: 16px; font-weight: 800; line-height: 24px; padding: 10px; border-top: 3px solid #eeeeee; border-bottom: 3px solid #eeeeee;">
                                                    {{ trans('generals.total') }}
                                                </td>
                                                <td width="22%" align="left" style="font-size: 16px; font-weight: 800; line-height: 24px; padding: 10px; border-top: 3px solid #eeeeee; border-bottom: 3px solid #eeeeee;">
                                                    {{ $ordersTot }} €
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="78%" align="left" style="font-size: 16px; font-weight: 800; line-height: 24px; padding: 10px; border-top: 3px solid #eeeeee; border-bottom: 3px solid #eeeeee;">
                                                    {{ trans('generals.total_tax') }}
                                                </td>
                                                <td width="22%" align="left" style="font-size: 16px; font-weight: 800; line-height: 24px; padding: 10px; border-top: 3px solid #eeeeee; border-bottom: 3px solid #eeeeee;">
                                                    {{ $ordersTotTaxIncl }} €
                                                </td>
                                            </tr>
                                        </table>
                                        <table align="left" border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td align="left" valign="top" style="font-size: 16px; font-weight: 400; line-height: 24px;">
                                                    <p style="font-weight: 800;">{{ trans('generals.company_details') }}</p>
                                                    <ul>
                                                        <li>{{ $exhibitor['company'] }}</li>
                                                        <li>{{ $exhibitor['address']['line1'] }}</li>
                                                        <li>{{ $exhibitor['address']['city'] }}
                                                            ({{ $exhibitor['address']['state'] }})
                                                            - {{ $exhibitor['address']['postal_code'] }}</li>
                                                        <li>Email:{{ $exhibitor['email'] }}</li>
                                                        <li>Tel: {{ $exhibitor['phone'] }}</li>
                                                        <li>P.IVA {{ $exhibitor['vat_number'] }}</li>
                                                    </ul>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            @include('partials.email-footer')
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
@endsection


