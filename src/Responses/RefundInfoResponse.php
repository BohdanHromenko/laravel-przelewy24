<?php
declare(strict_types=1);

namespace Devpark\Transfers24\Responses;

use Devpark\Transfers24\Contracts\IResponse;
use Devpark\Transfers24\Contracts\Transaction;
use Devpark\Transfers24\Exceptions\TestConnectionException;

class RefundInfoResponse extends Response implements IResponse
{
    public function getRefundInfo():array
    {
        return $this->decoded_body->getData();
    }
}
