<?php

namespace TFG\Features\Newsletter;

final class Feature
{
    public function register(): void
    {
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Newsletter\NewsletterSubscription::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Newsletter\NewsletterUnsubscribe::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Newsletter\SubscriberConfirm::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Newsletter\SubscriberUtilities::class);
    }
}
