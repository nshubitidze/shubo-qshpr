<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Ui;

/**
 * The single source of truth for the carrier code.
 *
 * Holds only the code until checkout needs module config. The code has no
 * underscore on purpose: Magento splits a shipping method code on its first
 * underscore to find the carrier (Order::getShippingMethod(true)), so
 * "shubo_qshpr" would read back as carrier "shubo".
 */
class ConfigProvider
{
    public const string CODE = 'shuboqshpr';
}
