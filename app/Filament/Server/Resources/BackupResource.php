<?php

namespace App\Filament\Server\Resources;

use App\Enums\BackupStatus;
use App\Enums\ServerState;
use App\Facades\Activity;
use App\Filament\Server\Resources\BackupResource\Pages;
use App\Http\Controllers\Api\Client\Servers\BackupController;
use App\Models\Backup;
use App\Models\Permission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\DownloadLinkService;
use App\Filament\Components\Tables\Columns\BytesColumn;
use App\Filament\Components\Tables\Columns\DateTimeColumn;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class BackupResource extends Resource
{
    protected static ?string $model = Backup::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'tabler-file-zip';

    protected static bool $canCreateAnother = false;

    public const WARNING_THRESHOLD = 0.7;

    public static function getNavigationBadge(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $limit = $server->backup_limit;

        return $server->backups->count() . ($limit === 0 ? '' : ' / ' . $limit);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $limit = $server->backup_limit;
        $count = $server->backups->count();

        if ($limit === 0) {
            return null;
        }

        return $count >= $limit ? 'danger' : ($count >= $limit * self::WARNING_THRESHOLD ? 'warning' : 'success');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label(trans('server/backup.list.name'))
                    ->columnSpanFull(),
                TextArea::make('ignored')
                    ->columnSpanFull()
                    ->label(trans('server/backup.list.ignore')),
                Toggle::make('is_locked')
                    ->label(trans('server/backup.list.lock'))
                    ->helperText(trans('server/backup.list.lock_help')),
            ]);
    }

    public static function table(Table $table): Table
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                BytesColumn::make('bytes')
                    ->label(trans('server/backup.list.size')),
                DateTimeColumn::make('created_at')
                    ->label(trans('server/backup.list.created'))
                    ->since()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(trans('server/backup.list.status'))
                    ->badge(),
                IconColumn::make('is_locked')
                    ->visibleFrom('md')
                    ->label(trans('server/backup.list.lock_status'))
                    ->trueIcon('tabler-lock')
                    ->falseIcon('tabler-lock-open'),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('lock')
                        ->icon(fn (Backup $backup) => !$backup->is_locked ? 'tabler-lock' : 'tabler-lock-open')
                        ->authorize(fn () => auth()->user()->can(Permission::ACTION_BACKUP_DELETE, $server))
                        ->label(fn (Backup $backup) => !$backup->is_locked ? trans('server/backup.list.lockable.lock') : trans('server/backup.list.lockable.unlock'))
                        ->action(fn (BackupController $backupController, Backup $backup, Request $request) => $backupController->toggleLock($request, $server, $backup))
                        ->visible(fn (Backup $backup) => $backup->status === BackupStatus::Successful),
                    Action::make('download')
                        ->color('primary')
                        ->icon('tabler-download')
                        ->authorize(fn () => auth()->user()->can(Permission::ACTION_BACKUP_DOWNLOAD, $server))
                        ->url(fn (DownloadLinkService $downloadLinkService, Backup $backup, Request $request) => $downloadLinkService->handle($backup, $request->user()), true)
                        ->visible(fn (Backup $backup) => $backup->status === BackupStatus::Successful),
                    Action::make('restore')
                        ->color('success')
                        ->icon('tabler-folder-up')
                        ->authorize(fn () => auth()->user()->can(Permission::ACTION_BACKUP_RESTORE, $server))
                        ->form([
                            Placeholder::make('')
                                ->helperText(trans('server/backup.list.restore_help')),
                            Checkbox::make('truncate')
                                ->label(trans('server/backup.list.restore_confirm')),
                        ])
                        ->action(function (Backup $backup, $data, DaemonBackupRepository $daemonRepository, DownloadLinkService $downloadLinkService) use ($server) {
                            if (!is_null($server->status)) {
                                return Notification::make()
                                    ->danger()
                                    ->title(trans('server/backup.list.restore_failed.cannot_restore'))
                                    ->body(trans('server/backup.list.restore_failed.cannot_restore_desc'))
                                    ->send();
                            }

                            if (!$backup->is_successful && is_null($backup->completed_at)) { //TODO Change to Notifications
                                return Notification::make()
                                    ->danger()
                                    ->title(trans('server/backup.list.restore_failed.restore_incomplete'))
                                    ->body(trans('server/backup.list.restore_failed.restore_incomplete_desc'))
                                    ->send();
                            }

                            $log = Activity::event('server:backup.restore')
                                ->subject($backup)
                                ->property(['name' => $backup->name, 'truncate' => $data['truncate']]);

                            $log->transaction(function () use ($downloadLinkService, $daemonRepository, $backup, $server, $data) {
                                // If the backup is for an S3 file we need to generate a unique Download link for
                                // it that will allow daemon to actually access the file.
                                if ($backup->disk === Backup::ADAPTER_AWS_S3) {
                                    $url = $downloadLinkService->handle($backup, auth()->user());
                                }

                                // Update the status right away for the server so that we know not to allow certain
                                // actions against it via the Panel API.
                                $server->update(['status' => ServerState::RestoringBackup]);

                                $daemonRepository->setServer($server)->restore($backup, $url ?? null, $data['truncate']);
                            });

                            return Notification::make()
                                ->title(trans('server/backup.list.restoring'))
                                ->send();
                        })
                        ->visible(fn (Backup $backup) => $backup->status === BackupStatus::Successful),
                    DeleteAction::make('delete')
                        ->disabled(fn (Backup $backup) => $backup->is_locked)
                        ->modalDescription(fn (Backup $backup) => trans('server/backup.list.delete', [
                            'backup' => $backup->name,
                        ]))
                        ->modalSubmitActionLabel(trans('server/backup.list.delete'))
                        ->action(fn (BackupController $backupController, Backup $backup, Request $request) => $backupController->delete($request, $server, $backup))
                        ->visible(fn (Backup $backup) => $backup->status !== BackupStatus::InProgress),
                ]),
            ]);
    }

    // TODO: find better way handle server conflict state
    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        if ($server->isInConflictState()) {
            return false;
        }

        return parent::canAccess();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->can(Permission::ACTION_BACKUP_READ, Filament::getTenant());
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can(Permission::ACTION_BACKUP_CREATE, Filament::getTenant());
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can(Permission::ACTION_BACKUP_DELETE, Filament::getTenant());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBackups::route('/'),
        ];
    }
}
