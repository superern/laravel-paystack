<?php

namespace Superern\Paystack\Enums;

enum PaystackEndpoint
{
    public const TRANSFER = '/transfer';
    public const CUSTOMER = '/customer';
    public const RECIPIENT = '/transferrecipient';
    public const PLAN = '/plan';
    public const TRANSACTION = '/transaction';
    public const SUBSCRIPTION = '/subscription';
    public const PAGE = '/page';
    public const SUBACCOUNT = '/subaccount';
}
