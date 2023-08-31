<?php

function hasOrder($email) {
    $exhibitor_data = \DB::table('exhibitors_data')->where('email_responsible', '=', $email)->first();
    if(!is_object($exhibitor_data)) {
        return false;
    }
    $exhibitor_id = $exhibitor_data->exhibitor_id;
    $exhibitor_orders = \DB::table('orders')->where('exhibitor_id', '=', $exhibitor_id)->get();
    if(count($exhibitor_orders) > 0) {
        return true;
    }
    return false;
}