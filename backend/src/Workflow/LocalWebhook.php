<?php
declare(strict_types=1);
namespace App\Workflow;

/** Deliberate local integration double: records an audit event, performs no network I/O. */
final class LocalWebhook
{
    public static function validate(array$config):void
    {
        if(array_diff(array_keys($config),['endpoint_id'])||($config['endpoint_id']??'')!=='local-audit'){throw new \InvalidArgumentException('Choose the allowlisted local-audit dummy endpoint; workflow URLs are not accepted.');}
    }
    public static function requireEnabled():void
    {
        if(!in_array(getenv('APP_ENV'),['development','offline','test'],true)||getenv('ALLOW_DEMO_INTEGRATIONS')!=='true'){throw new \InvalidArgumentException('The local webhook dummy is disabled outside explicitly enabled local environments.');}
    }
}
