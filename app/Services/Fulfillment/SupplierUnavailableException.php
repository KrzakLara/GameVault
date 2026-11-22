<?php

namespace App\Services\Fulfillment;

use RuntimeException;

/** A transient supplier failure: fulfillment relinquishes its lease and the queue retries. */
final class SupplierUnavailableException extends RuntimeException {}
