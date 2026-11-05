<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * What became of a command Airlock was asked to run.
 */
enum Outcome: string
{
    case RefusedByPolicy = 'refused_by_policy';
    case RefusedByUser = 'refused_by_user';
    case RanOnce = 'ran_once';
    case RanAndGrantedForSession = 'ran_and_granted_for_session';
    case RanAndGrantedForProject = 'ran_and_granted_for_project';
    case RanUnderGrant = 'ran_under_grant';
    case Failed = 'failed';
    case ReadFromGitHub = 'read_from_github';
    case OpenedOnGitHub = 'opened_on_github';
}
