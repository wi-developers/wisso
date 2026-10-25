<?php

declare(strict_types=1);

// Order matters: the specific routes first, the JSON catch-alls for the admin-api prefix last.
return [
	'routes' => [
		['name' => 'api#login', 'url' => '/admin-api/sso/login', 'verb' => 'POST'],
		['name' => 'api#update', 'url' => '/admin-api/sso/update', 'verb' => 'POST'],
		['name' => 'sso#consume', 'url' => '/sso/consume', 'verb' => 'GET'],
		['name' => 'sso#claim', 'url' => '/sso/claim', 'verb' => 'GET'],
		['name' => 'sso#claimConfirm', 'url' => '/sso/claim', 'verb' => 'POST'],
		['name' => 'api#fallbackGet', 'url' => '/admin-api/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
		['name' => 'api#fallbackPost', 'url' => '/admin-api/{path}', 'verb' => 'POST', 'requirements' => ['path' => '.+']],
		['name' => 'api#fallbackPut', 'url' => '/admin-api/{path}', 'verb' => 'PUT', 'requirements' => ['path' => '.+']],
		['name' => 'api#fallbackDelete', 'url' => '/admin-api/{path}', 'verb' => 'DELETE', 'requirements' => ['path' => '.+']],
		['name' => 'api#fallbackPatch', 'url' => '/admin-api/{path}', 'verb' => 'PATCH', 'requirements' => ['path' => '.+']],
		['name' => 'api#fallbackOptions', 'url' => '/admin-api/{path}', 'verb' => 'OPTIONS', 'requirements' => ['path' => '.+']],
	],
];
