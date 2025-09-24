<?php

namespace TFG\Features\MagicLogin;

final class Feature
{
    public function register(): void
    {
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\MagicLogin\DebugTools::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\MagicLogin\MagicHandler::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\MagicLogin\MagicLogin::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\MagicLogin\MagicUtilities::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\MagicLogin\ResetTokenCPT::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\MagicLogin\VerificationController::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\MagicLogin\VerificationToken::class);
    }
}
