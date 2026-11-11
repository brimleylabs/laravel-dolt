<?php

namespace BrimleyLabs\Dolt\Events;

use BrimleyLabs\Dolt\Workspaces\Workspace;

class WorkspaceOpened
{
    public function __construct(public readonly Workspace $workspace) {}
}
