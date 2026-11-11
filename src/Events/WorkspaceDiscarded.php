<?php

namespace BrimleyLabs\Dolt\Events;

use BrimleyLabs\Dolt\Workspaces\Workspace;

class WorkspaceDiscarded
{
    public function __construct(public readonly Workspace $workspace) {}
}
