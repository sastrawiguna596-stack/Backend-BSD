<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::where('email', 'guru@gmail.com')->first();
if (!$user) { echo "User guru@gmail.com not found\n"; exit; }
Illuminate\Support\Facades\Auth::login($user);
$request = Illuminate\Http\Request::create('/api/v1/classrooms', 'GET');
$request->setUserResolver(function() use ($user) { return $user; });

echo "=== API /classrooms ===\n";
$ctrl = new App\Http\Controllers\ClassroomController();
$res = $ctrl->index($request);
echo json_encode($res->getData(), JSON_PRETTY_PRINT) . "\n";

echo "\n=== API /class-sessions ===\n";
$request2 = Illuminate\Http\Request::create('/api/v1/class-sessions', 'GET');
$request2->setUserResolver(function() use ($user) { return $user; });
$ctrl2 = new App\Http\Controllers\ClassSessionController();
try {
    $res2 = $ctrl2->index($request2);
    echo json_encode($res2->getData(), JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo "ERROR in ClassSessionController: " . $e->getMessage() . "\n";
}

echo "\n=== API /class-schedules ===\n";
$request3 = Illuminate\Http\Request::create('/api/v1/class-schedules', 'GET');
$request3->setUserResolver(function() use ($user) { return $user; });
$ctrl3 = new App\Http\Controllers\ClassScheduleController();
try {
    $res3 = $ctrl3->index($request3);
    echo json_encode($res3->getData(), JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo "ERROR in ClassScheduleController: " . $e->getMessage() . "\n";
}