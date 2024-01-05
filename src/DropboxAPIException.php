<?php

declare(strict_types=1);

namespace DropboxAPI;

class DropboxAPIException extends \Exception {

    public const TOKEN_EXPIRED = 'The access token has expired.';
    public const RATE_LIMIT_STATUS = 429;

    /**
     * Returns whether the exception was thrown because of an expired access token.
     *
     * @return bool
     */
    public function hasExpiredToken(): bool {
        return $this->getMessage() === self::TOKEN_EXPIRED;
    }

    /**
     * Returns whether the exception was thrown because of rate limiting.
     *
     * @return bool
     */
    public function isRateLimited(): bool {
        return $this->getCode() === self::RATE_LIMIT_STATUS;
    }
}
