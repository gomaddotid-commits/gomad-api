<?php

namespace App\Core\Payment\Domain;

use RuntimeException;

final class InvalidWebhookSignature extends RuntimeException {}
