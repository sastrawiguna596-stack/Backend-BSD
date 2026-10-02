<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::where('email', 'guru@gmail.com')->first();
echo "User: " . ($user ? $user->id : 'not found') . "\n";

$teacher = App\Models\Teacher::where('user_id', $user->id)->first();
echo "Teacher: " . ($teacher ? $teacher->id : 'not found') . "\n";

$classrooms = App\Models\Classroom::whereHas('teachers', function ($q) use ($teacher) {
    $q->where('teachers.id', $teacher->id);
})->get();
echo "Classrooms mapped to teacher: " . $classrooms->count() . "\n";

foreach ($classrooms as $c) {
    echo "- " . $c->name . "\n";
}

$allClassrooms = App\Models\Classroom::with('teachers')->get();
echo "Total Classrooms: " . $allClassrooms->count() . "\n";
foreach($allClassrooms as $ac) {
    echo "Class: " . $ac->name . " -> Teachers: ";
    foreach($ac->teachers as $t) {
        echo $t->id . " (" . $t->user->name . "), ";
    }
    echo "\n";
}