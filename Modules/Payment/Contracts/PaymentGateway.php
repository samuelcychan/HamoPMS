<?php

namespace Modules\Payment\Contracts;

use Modules\Payment\Data\PaymentIntentRequest;
use Modules\Payment\Data\PaymentIntentResult;
use Modules\Payment\Data\PaymentOperationRequest;
use Modules\Payment\Data\PaymentOperationResult;

interface PaymentGateway
{
    public function name(): string;

    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult;

    public function capture(PaymentOperationRequest $request): PaymentOperationResult;

    public function refund(PaymentOperationRequest $request): PaymentOperationResult;

    public function void(PaymentOperationRequest $request): PaymentOperationResult;
}
