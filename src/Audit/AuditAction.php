<?php

declare(strict_types=1);

namespace App\Audit;

enum AuditAction: string
{
    case EventCreate = 'event.create';
    case EventEdit = 'event.edit';
    case EventDelete = 'event.delete';
    case EventPublish = 'event.publish';
    case EventNotificationsToggle = 'event.notifications_toggle';
    case EventExport = 'event.export';
    case EventImport = 'event.import';
    case EventBibSuppress = 'event.bib_suppress';

    case CollectionCreate = 'collection.create';
    case CollectionEdit = 'collection.edit';
    case CollectionDelete = 'collection.delete';

    case PhotoDelete = 'photo.delete';
    case PhotoDeleteAll = 'photo.delete_all';
    case PhotoReingest = 'photo.reingest';
    case PhotoReingestAll = 'photo.reingest_all';
    case PhotoRetry = 'photo.retry';

    case UserCreate = 'user.create';
    case UserEdit = 'user.edit';
    case UserRoleChange = 'user.role_change';
    case UserDelete = 'user.delete';
    case UserSendReset = 'user.send_reset';
    case UserStyleChange = 'user.style_change';
    case UserIdentityUnlink = 'user.identity_unlink';

    case InviteCreate = 'invite.create';
    case InviteRevoke = 'invite.revoke';

    case MailConfigUpdate = 'mailconfig.update';
    case MailConfigVerify = 'mailconfig.verify';
    case MailConfigResend = 'mailconfig.resend';
    case MailConfigClear = 'mailconfig.clear';

    case SessionRevoke = 'session.revoke';

    case AuthLoginSuccess = 'auth.login_success';
    case AuthLoginFailure = 'auth.login_failure';
    case AuthLogout = 'auth.logout';

    case OAuthLink = 'oauth.link';
    case InviteRedeem = 'invite.redeem';

    public function category(): string
    {
        return match (explode('.', $this->value, 2)[0]) {
            'event' => 'Event',
            'collection' => 'Collection',
            'photo' => 'Photo',
            'user' => 'User',
            'invite' => 'Invitation',
            'mailconfig' => 'Mail configuration',
            'session' => 'Session',
            'auth' => 'Authentication',
            'oauth' => 'Identity',
            default => 'Other',
        };
    }

    public function label(): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $this->value));
    }
}
