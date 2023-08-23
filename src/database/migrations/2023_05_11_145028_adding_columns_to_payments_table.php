<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddingColumnsToPaymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('stand_type_id');
            $table->foreign('stand_type_id')->references('id')->on('stands_types')->onDelete('cascade');
            $table->integer('n_modules')->nullable();
            $table->enum('type_of_payment', ['subscription', 'furnishing'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['user_id', 'event_id', 'stand_type_id']);
            $table->dropColumn(['user_id', 'event_id', 'stand_type_id', 'n_modules', 'type_of_payment']);
        });
    }
}
