<?php

namespace App\Services\Rooms;

use App\Services\Admin\PermissionAuthorization;

// Compatibility entry point: Rooms keeps its routes, service and permission semantics.
class RoomAuthorization extends PermissionAuthorization {}
