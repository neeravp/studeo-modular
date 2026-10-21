<?php

namespace Studeo\Modular\Tests\Support;

use Studeo\Modular\DataObjects\PivotGenerationOptions;

final class PivotCases
{
    public const UNPARSEABLE = "Cannot parse two entities from pivot name [%s]. Please provide both models (e.g. 'Flight Passenger' or 'FlightPassenger').";

    /**
     * Each case: the fixture it is compared with, the models already in the module, the two
     * arguments, the options, and the failure it ends in.
     *
     * @return array<string, array{string, array<int, string>, string, ?string, PivotGenerationOptions, ?string}>
     */
    public static function calls(): array
    {
        $exists = 'Pivot model [FlightPassenger] already exists in module [Account].';
        $unparseable = "Cannot parse two entities from pivot name [Singleword]. Please provide both models (e.g. 'Flight Passenger' or 'FlightPassenger').";

        return [
            'pair' => ['pair', [], 'Passenger', 'Flight', new PivotGenerationOptions, null],
            'pair-morph' => ['pair-morph', [], 'Tag', 'Post', new PivotGenerationOptions(morph: true), null],
            'pair-no-migration' => ['pair-no-migration', [], 'Flight', 'Passenger', new PivotGenerationOptions(withMigration: false), null],
            'pair-custom' => ['pair-custom', [], 'Flight', 'Passenger', new PivotGenerationOptions(table: 'trip_person', foreignKey1: 'trip', foreignKey2: 'person'), null],
            'compound-snake' => ['compound-snake', [], 'passenger_flight', null, new PivotGenerationOptions, null],
            'compound-discovered' => ['compound-discovered', ['UserProfile', 'Role'], 'UserProfileRole', null, new PivotGenerationOptions, null],
            'compound-pascal' => ['compound-pascal', ['Unrelated'], 'PassengerFlight', null, new PivotGenerationOptions, null],
            'error-exists' => ['error-exists', ['FlightPassenger'], 'Flight', 'Passenger', new PivotGenerationOptions, $exists],
            'error-exists-compound' => ['error-exists-compound', ['FlightPassenger'], 'FlightPassenger', null, new PivotGenerationOptions, $exists],
            'error-unparseable' => ['error-unparseable', ['Flight'], 'Singleword', null, new PivotGenerationOptions, $unparseable],
            'error-invalid-pair' => ['error-invalid-pair', [], '/', 'Flight', new PivotGenerationOptions, 'Invalid pivot model names [/], [Flight].'],
            'error-invalid-compound' => ['error-invalid-compound', ['Flight'], '  ', null, new PivotGenerationOptions, 'Invalid pivot name [  ].'],
            'error-migration-table' => ['error-migration-table', [], 'Flight', 'Passenger', new PivotGenerationOptions(table: 'trips'), 'Pivot table name [trips] must contain at least two parts (e.g. flight_passenger).'],
        ];
    }
}
