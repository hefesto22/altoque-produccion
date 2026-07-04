<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cais;

use App\Filament\Resources\Cais\Pages\ManageCais;
use App\Models\Cai;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CaiResource extends Resource
{
    protected static ?string $model = Cai::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $modelLabel = 'CAI';

    protected static ?string $pluralModelLabel = 'Rangos CAI';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Facturación';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del CAI')
                ->description('Información de la autorización emitida por el SAR.')
                ->schema([
                    TextInput::make('codigo')
                        ->label('Código CAI')
                        ->required()
                        ->placeholder('XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX')
                        ->helperText('32 hexadecimales en 6 bloques. Cada CAI autoriza un solo tipo de documento.')
                        ->columnSpanFull(),
                    DatePicker::make('fecha_autorizacion')->label('Fecha de autorización')->required()->native(false),
                    DatePicker::make('fecha_limite_emision')->label('Fecha límite de emisión')->required()->native(false),
                    Select::make('tipo_documento')
                        ->label('Tipo de documento')
                        ->required()
                        ->live()
                        ->default('01')
                        ->options([
                            '01' => 'Factura (01)',
                            '03' => 'Nota de Crédito (03)',
                            '04' => 'Nota de Débito (04)',
                        ])
                        ->helperText('Código SAR según Acuerdo 481-2017.'),
                ])->columns(2),

            Section::make('Prefijo y rango autorizado')
                ->description('Punto de emisión y numeración correlativa autorizada por el SAR.')
                ->schema([
                    TextInput::make('establecimiento')->label('Establecimiento')->required()->live()->default('000')->maxLength(3)->mask('999'),
                    TextInput::make('punto_emision')->label('Punto de emisión')->required()->live()->default('001')->maxLength(3)->mask('999'),
                    TextInput::make('prefijo_preview')
                        ->label('Prefijo (Est-Punto-Tipo)')
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('000-001-01')
                        ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(
                            sprintf('%s-%s-%s', $get('establecimiento') ?? '000', $get('punto_emision') ?? '001', $get('tipo_documento') ?? '01')
                        )),
                    TextInput::make('correlativo_desde')->label('Número inicial')->required()->numeric()->minValue(1)->placeholder('1'),
                    TextInput::make('correlativo_hasta')->label('Número final')->required()->numeric()->minValue(1)->placeholder('500'),
                    TextInput::make('correlativo_actual')->label('Número actual')->required()->numeric()->minValue(0)->default(0)
                        ->helperText('Se llena automáticamente al emitir. Solo editar si migra datos existentes.'),
                ])->columns(3),

            Section::make('Estado')
                ->description('Al activar un CAI se desactivan automáticamente los demás del mismo tipo de documento.')
                ->schema([
                    Select::make('estado')->label('Estado')->required()->default('activo')->options([
                        'activo'   => 'Activo',
                        'agotado'  => 'Agotado',
                        'vencido'  => 'Vencido',
                        'inactivo' => 'Inactivo',
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')->label('CAI')->searchable()->limit(20)->copyable(),
                TextColumn::make('prefijo')->label('Prefijo')->state(fn ($record): string => $record->prefijo()),
                TextColumn::make('tipo_documento')->label('Tipo')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    '01' => 'Factura', '03' => 'Nota Crédito', '04' => 'Nota Débito', default => $state,
                }),
                TextColumn::make('estado')->label('Estado')->badge()->color(fn (string $state): string => match ($state) {
                    'activo'              => 'success',
                    'agotado'             => 'warning',
                    'vencido', 'inactivo' => 'danger',
                    default               => 'gray',
                }),
                TextColumn::make('correlativo_actual')->label('Usado')
                    ->formatStateUsing(fn ($state, $record): string => "{$state} / {$record->correlativo_hasta}"),
                TextColumn::make('fecha_limite_emision')->label('Vence')->date('d/m/Y')
                    ->color(fn ($record): string => $record->fecha_limite_emision->isPast() ? 'danger' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('estado')->options([
                    'activo' => 'Activo', 'agotado' => 'Agotado', 'vencido' => 'Vencido', 'inactivo' => 'Inactivo',
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCais::route('/'),
        ];
    }
}
