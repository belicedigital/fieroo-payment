<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exhibitor_id');
            $table->foreign('exhibitor_id')->references('id')->on('exhibitors')->onDelete('cascade');
            // $table->unsignedBigInteger('code_module_id');
            // $table->foreign('code_module_id')->references('id')->on('code_modules')->onDelete('cascade');
            $table->unsignedBigInteger('furnishing_id');
            $table->foreign('furnishing_id')->references('id')->on('furnishings')->onDelete('cascade');
            $table->integer('qty');
            $table->boolean('is_supplied')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders');
    }
}
