<?php

namespace BrimleyLabs\Dolt\Events;

use BrimleyLabs\Dolt\Data\MergeResult;
use BrimleyLabs\Dolt\Workspaces\Workspace;

class WorkspacePublished
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly MergeResult $result,
    ) {}
}
