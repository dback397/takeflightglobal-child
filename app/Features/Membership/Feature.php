<?php

namespace TFG\Features\Membership;

final class Feature
{
    public function register(): void
    {
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\ACFValidator::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberDashboard::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberFormHandlers::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberFormUtilities::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberGdprConsent::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberDeletion::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberIDGenerator::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberLogin::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberProfileDisplay::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberProfileEditor::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberProfileDeactivation::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\Membership::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberStubAccess::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\MemberStubManager::class);
        \TFG\Core\Bootstrap::tryInit(\TFG\Features\Membership\UniversityForm::class);
    }
}
