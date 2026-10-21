<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreatePivotAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\PivotGenerationOptions;
use Studeo\Modular\Tests\Support\PivotCases;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->accountModule = new ModuleDefinition(
        name: 'Account',
        namespace: 'Studeo\\Account',
        path: $this->rootModulesPath.'/Account',
    );

    app(CreateModuleAction::class)->execute($this->accountModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

const PIVOT_INVALID = 'Invalid pivot name [';

/**
 * Each case: the models already in the module, the two arguments, and the model name, table and
 * unique-key columns that result.
 *
 * @return array<string, array{array<int, string>, string, ?string, array<int, string>}>
 */
function pivotResolutionCases(): array
{
    return [
        'pair of snake_case names' => [[], 'user_profile', 'role', ['RoleUserProfile', 'role_user_profile', 'role_id', 'user_profile_id']],
        'pair with a space' => [[], 'user profile', 'role', ['RoleUserProfile', 'role_user_profile', 'role_id', 'user_profile_id']],
        'pair with separators at the ends' => [[], 'Flight/', '\\Passenger', ['FlightPassenger', 'flight_passenger', 'flight_id', 'passenger_id']],
        'pair with separators inside and spaces around' => [[], 'Fli/gh\\t', ' Passenger ', ['FlightPassenger', 'flight_passenger', 'flight_id', 'passenger_id']],
        'pair of the same model' => [[], 'Flight', 'Flight', ['FlightFlight', 'flight_flight', 'flight_id', 'flight_id']],
        'pair with an uppercase second name keeps its case' => [[], 'flight', 'PASSENGER', ['FlightPASSENGER', 'flight_p_a_s_s_e_n_g_e_r', 'flight_id', 'p_a_s_s_e_n_g_e_r_id']],
        'blank second model falls back to the compound name' => [[], 'FlightPassenger', '  ', ['FlightPassenger', 'flight_passenger', 'flight_id', 'passenger_id']],
        'empty second model falls back to the compound name' => [[], 'FlightPassenger', '', ['FlightPassenger', 'flight_passenger', 'flight_id', 'passenger_id']],
        'snake case with three words splits after the first' => [[], 'user_profile_role', null, ['ProfileRoleUser', 'profile_role_user', 'profile_role_id', 'user_id']],
        'snake case with an empty segment' => [[], 'flight__passenger', null, ['FlightPassenger', 'flight_passenger', 'flight_id', 'passenger_id']],
        'snake case with a capitalised first part' => [[], 'Flight_Passenger', null, ['FlightPassenger', 'flight_passenger', 'flight_id', 'passenger_id']],
        'snake case with single letters' => [[], 'a_b_c', null, ['ABC', 'a_b_c', 'a_id', 'b_c_id']],
        'compound with a separator inside' => [[], 'Flight/Passenger', null, ['FlightPassenger', 'flight_passenger', 'flight_id', 'passenger_id']],
        'compound with spaces around' => [[], '  FlightPassenger  ', null, ['FlightPassenger', 'flight_passenger', 'flight_id', 'passenger_id']],
        'pascal case of four capitals' => [[], 'ABCD', null, ['ABCD', 'a_b_c_d', 'a_b_id', 'c_d_id']],
        'pascal case of three capitals' => [[], 'ABC', null, ['ABC', 'a_b_c', 'a_b_id', 'c_id']],
        'pascal case of two capitals' => [[], 'AB', null, ['AB', 'a_b', 'a_id', 'b_id']],
        'pascal case with an acronym' => [[], 'HTTPServer', null, ['HTTPServer', 'h_t_t_p_server', 'h_t_t_id', 'p_server_id']],
        'pascal case with a digit inside a word' => [[], 'Flight2Passenger', null, ['Flight2Passenger', 'flight2_passenger', 'flight2_id', 'passenger_id']],
        'pascal case of three words gives the first half the extra word' => [[], 'ThreeWordName', null, ['NameThreeWord', 'name_three_word', 'name_id', 'three_word_id']],
        'pascal case of four words' => [[], 'FourWordLongName', null, ['FourWordLongName', 'four_word_long_name', 'four_word_id', 'long_name_id']],
        'pascal case of five words' => [[], 'OneTwoThreeFourFive', null, ['FourFiveOneTwoThree', 'four_five_one_two_three', 'four_five_id', 'one_two_three_id']],
        'pascal case with a lowercase first word sorts after capitals' => [[], 'flightPassenger', null, ['Passengerflight', 'passenger_flight', 'passenger_id', 'flight_id']],
        'discovered models: the first prefix in name order wins' => [['User', 'UserProfile'], 'UserProfileRole', null, ['ProfileRoleUser', 'profile_role_user', 'profile_role_id', 'user_id']],
        'discovered model with a lowercase remainder' => [['Flight'], 'Flightpassenger', null, ['FlightPassenger', 'flight_passenger', 'flight_id', 'passenger_id']],
        'discovered models match the case exactly' => [['Flight'], 'flightPassenger', null, ['Passengerflight', 'passenger_flight', 'passenger_id', 'flight_id']],
        'snake case is tried before discovered models' => [['UserProfile', 'Role'], 'user_profile_role', null, ['ProfileRoleUser', 'profile_role_user', 'profile_role_id', 'user_id']],
        'discovered models are ignored when both models are given' => [['UserProfile'], 'User', 'ProfileRole', ['ProfileRoleUser', 'profile_role_user', 'profile_role_id', 'user_id']],
    ];
}

it('resolves the two entities of a pivot from every kind of name', function (array $models, string $first, ?string $second, array $expected) {
    expect(pivotResolution($this->accountModule, $models, $first, $second))->toBe($expected);
})->with(pivotResolutionCases());

it('uses the given foreign keys for the entities in alphabetical order', function (PivotGenerationOptions $options, array $expected) {
    expect(pivotResolution($this->accountModule, [], 'Passenger', 'Flight', $options))->toBe($expected);
})->with([
    'both keys' => [new PivotGenerationOptions(foreignKey1: 'trip', foreignKey2: 'person'), ['FlightPassenger', 'flight_passenger', 'trip', 'person']],
    'only the second key' => [new PivotGenerationOptions(foreignKey2: 'person'), ['FlightPassenger', 'flight_passenger', 'flight_id', 'person']],
    'keys and table' => [new PivotGenerationOptions(table: 'trip_person', foreignKey1: 'trip', foreignKey2: 'person'), ['FlightPassenger', 'trip_person', 'trip', 'person']],
]);

/**
 * Each case: the models already in the module, the two arguments, and the exact error.
 *
 * @return array<string, array{array<int, string>, string, ?string, string}>
 */
function pivotFailureCases(): array
{
    return [
        'one word' => [[], 'Singleword', null, sprintf(PivotCases::UNPARSEABLE, 'Singleword')],
        'one word with a digit' => [[], 'Flight2', null, sprintf(PivotCases::UNPARSEABLE, 'Flight2')],
        'one word in lowercase' => [[], 'flightpassenger', null, sprintf(PivotCases::UNPARSEABLE, 'flightpassenger')],
        'a hyphen is not a separator' => [[], 'flight-passenger', null, sprintf(PivotCases::UNPARSEABLE, 'flight-passenger')],
        'a leading underscore leaves one part' => [[], '_flight', null, sprintf(PivotCases::UNPARSEABLE, '_flight')],
        'a trailing underscore leaves one part' => [[], 'flight_', null, sprintf(PivotCases::UNPARSEABLE, 'flight_')],
        'a zero part is kept and leads a digit entity' => [[], 'flight_0', null, PIVOT_INVALID.'flight_0].'],
        'a zero segment is kept and leads a digit entity' => [[], 'flight_0_x', null, PIVOT_INVALID.'flight_0_x].'],
        'a zero as first model' => [[], '0', 'Flight', PIVOT_INVALID.'0].'],
        'a zero as second model' => [[], 'Flight', '0', PIVOT_INVALID.'0].'],
        'a numeric first model' => [[], '10', '9', PIVOT_INVALID.'10].'],
        'a digit-leading second model' => [[], 'Flight', '2Fa', PIVOT_INVALID.'2Fa].'],
        'a digit after the snake case split' => [[], 'flight_2', null, PIVOT_INVALID.'flight_2].'],
        'a digit-leading pascal case name' => [[], '2Flight', null, PIVOT_INVALID.'2Flight].'],
        'a discovered model whose name is a number' => [['123'], '123Role', null, PIVOT_INVALID.'123Role].'],
        'an underscore alone' => [[], '_', null, sprintf(PivotCases::UNPARSEABLE, '_')],
        'one capital letter' => [[], 'A', null, sprintf(PivotCases::UNPARSEABLE, 'A')],
        'a blank second model leaves the first as one word' => [[], 'Flight', '  ', sprintf(PivotCases::UNPARSEABLE, 'Flight')],
        'an empty second model leaves the first as one word' => [[], 'Flight', '', sprintf(PivotCases::UNPARSEABLE, 'Flight')],
        'a discovered model equal to the whole name' => [['Flight'], 'Flight', null, sprintf(PivotCases::UNPARSEABLE, 'Flight')],
        'an empty name' => [[], '', null, 'Invalid pivot name [].'],
        'a blank name' => [[], '   ', null, 'Invalid pivot name [   ].'],
        'a name of separators' => [[], '/', null, 'Invalid pivot name [/].'],
        'a name of backslashes and spaces' => [[], ' \\ \\ ', null, 'Invalid pivot name [ \\ \\ ].'],
        'an empty first model' => [[], '', 'Flight', 'Invalid pivot model names [], [Flight].'],
        'a first model of separators' => [[], ' / ', 'Flight', 'Invalid pivot model names [ / ], [Flight].'],
        'a second model of separators' => [[], 'Flight', '/', 'Invalid pivot model names [Flight], [/].'],
        'both models of separators' => [[], '\\', '/', 'Invalid pivot model names [\\], [/].'],
        'the pivot model exists under the first matching prefix' => [['Flight', 'FlightPassenger'], 'FlightPassenger', null, 'Pivot model [FlightPassenger] already exists in module [Account].'],
        'the pivot model exists for a pair' => [['FlightPassenger'], 'Passenger', 'Flight', 'Pivot model [FlightPassenger] already exists in module [Account].'],
    ];
}

it('refuses a name that gives no two entities, or a model that exists, and writes nothing', function (array $models, string $first, ?string $second, string $message) {
    seedPivotModels($this->accountModule, $models);
    $before = pivotModuleFiles($this->accountModule);

    expect(fn () => app(CreatePivotAction::class)->execute($this->accountModule, $first, $second))
        ->toThrow(RuntimeException::class, $message)
        ->and(pivotModuleFiles($this->accountModule))->toBe($before);
})->with(pivotFailureCases());

it('reports a missing module before it looks at the names', function () {
    $ghost = new ModuleDefinition('Ghost', 'Studeo\\Ghost', $this->rootModulesPath.'/Ghost');

    expect(fn () => app(CreatePivotAction::class)->execute($ghost, '/', '/'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghost->path}].")
        ->and(fn () => app(CreatePivotAction::class)->execute($ghost, 'Singleword'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghost->path}].");
});

it('writes the model without a migration when a name resolves and the migration is off', function () {
    $result = app(CreatePivotAction::class)->execute(
        $this->accountModule,
        'PassengerFlight',
        null,
        new PivotGenerationOptions(withMigration: false),
    );

    expect($result->migrationPath)->toBeNull()
        ->and(pivotModuleFiles($this->accountModule))->toContain($result->modelPath)
        ->and(File::allFiles($this->accountModule->path.'/database/migrations'))->toBe([]);
});

it('leaves the pivot model behind when the migration refuses the table', function (string $first, string $second, ?string $table, string $message, string $model) {
    expect(fn () => app(CreatePivotAction::class)->execute($this->accountModule, $first, $second, new PivotGenerationOptions(table: $table)))
        ->toThrow(RuntimeException::class, $message)
        ->and(File::exists("{$this->accountModule->path}/Models/{$model}.php"))->toBeTrue()
        ->and(File::allFiles($this->accountModule->path.'/database/migrations'))->toBe([]);
})->with([
    'a zero as the table' => ['Flight', 'Passenger', '0', 'Cannot determine pivot table name for migration [create_0_table].', 'FlightPassenger'],
    'a one-word custom table' => ['Flight', 'Passenger', 'trips', 'Pivot table name [trips] must contain at least two parts (e.g. flight_passenger).', 'FlightPassenger'],
]);
