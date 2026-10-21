<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

// it('reveals the testbench application paths', function () {
//     dump([
//         'base_path' => $this->app->basePath(),
//         'composer' => $this->app->basePath('composer.json'),
//         'autoload' => $this->app->basePath('vendor/autoload.php'),
//     ]);

//     expect($this->app->basePath('composer.json'))
//         ->toBeFile();

//     // expect($this->app->basePath('vendor/autoload.php'))
//     //     ->toBeFile();
// });
// it('reveals the testbench application composer configuration', function () {
//     dump(
//         json_decode(
//             File::get(
//                 $this->app->basePath('composer.json')
//             ),
//             true,
//             512,
//             JSON_THROW_ON_ERROR
//         )
//     );
// });

// it('can run composer dump autoload for the testbench application', function () {

//     $composerPath = $this->app->basePath('composer.json');

//     $composer = json_decode(
//         File::get($composerPath),
//         true,
//         512,
//         JSON_THROW_ON_ERROR
//     );

//     $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';

//     File::put(
//         $composerPath,
//         json_encode(
//             $composer,
//             JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
//         ) . PHP_EOL

//     );

//     // Stop here for the moment.
//     dump(File::get($composerPath));
//     expect($composerPath)->toBeFile();
// });
