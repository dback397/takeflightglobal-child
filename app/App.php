<?php

namespace TFG;

final class App
{
    public static function register(): void
    {
        // --- Core ---
        \TFG\Core\Bootstrap::tryInit(\TFG\Core\Utils::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Core\Cookies::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Core\Log::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Core\Assets::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Core\FormRouter::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Core\Mailer::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Core\Prefill::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Core\Recaptcha::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Core\RestAPI::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Core\ThemeSetup::class);
        
        // --- Admin ---
        \TFG\Core\Bootstrap::tryInit(\TFG\Admin\Sequence::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Admin\AdminProcesses::class);

        // --- UI ---
        \TFG\Core\Bootstrap::tryInit(\TFG\UI\Carousel::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\UI\ErrorModal::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\UI\ProfileButtons::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\UI\Shortcodes::class);
        
        // --- Features (grouped bootstraps) ---
        (new \TFG\Features\Membership\Feature())->register();
        (new \TFG\Features\MagicLogin\Feature())->register();
        (new \TFG\Features\Newsletter\Feature())->register();
        
    }
}
