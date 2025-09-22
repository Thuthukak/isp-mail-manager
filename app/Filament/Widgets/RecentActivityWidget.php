<?php

namespace App\Filament\Widgets;

use App\Models\BackupJob;
use App\Models\EmailAccount;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentActivityWidget extends BaseWidget
{
    protected static ?string $heading = 'Recent Backup Jobs';
    protected static ?int $sort = 4;
    
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                BackupJob::query()
                    ->with('emailAccount')
                    ->latest()
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('emailAccount.email')
                    ->label('Email Account')
                    ->sortable()
                    ->searchable(),
                    
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'completed',
                        'danger' => 'failed',
                        'warning' => 'running',
                        'secondary' => 'pending',
                    ])
                    ->icons([
                        'completed' => 'heroicon-o-check-circle',
                        'failed' => 'heroicon-o-x-circle',
                        'running' => 'heroicon-o-arrow-path',
                        'pending' => 'heroicon-o-clock',
                    ])
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('emails_backed_up')
                    ->label('Emails')
                    ->numeric()
                    ->sortable()
                    ->getStateUsing(fn ($record) => $record->emails_backed_up ? number_format($record->emails_backed_up) : '-'),
                    
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->getStateUsing(fn ($record) => $record->completed_at ? $record->completed_at->format('M j, Y H:i') : '-'),
                    
                Tables\Columns\TextColumn::make('duration')
                    ->label('Duration')
                    ->getStateUsing(function ($record) {
                        if (!$record->started_at) return '-';
                        
                        $endTime = $record->completed_at ?? now();
                        $duration = $record->started_at->diffInMinutes($endTime);
                        
                        if ($duration < 60) {
                            return "{$duration}m";
                        }
                        
                        $hours = floor($duration / 60);
                        $minutes = $duration % 60;
                        return "{$hours}h {$minutes}m";
                    }),
                    
                Tables\Columns\IconColumn::make('error_status')
                    ->label('Error')
                    ->boolean()
                    ->getStateUsing(fn ($record) => !empty($record->error_message))
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('')
                    ->trueColor('danger')
                    ->tooltip(fn ($record) => $record->error_message ?? ''),
            ])
            ->actions([
                Tables\Actions\Action::make('view_details')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading('Backup Job Details')
                    ->modalContent(function ($record) {
                        $content = '<div class="space-y-4">';
                        
                        $content .= '<div><strong>Account:</strong> ' . $record->emailAccount->email . '</div>';
                        $content .= '<div><strong>Status:</strong> <span class="badge badge-' . $record->status . '">' . ucfirst($record->status) . '</span></div>';
                        
                        if ($record->emails_backed_up) {
                            $content .= '<div><strong>Emails Backed Up:</strong> ' . number_format($record->emails_backed_up) . '</div>';
                        }
                        
                        if ($record->backup_path) {
                            $content .= '<div><strong>Backup Path:</strong> ' . $record->backup_path . '</div>';
                        }
                        
                        if ($record->mailboxes_backed_up) {
                            $content .= '<div><strong>Mailboxes:</strong><ul>';
                            foreach ($record->mailboxes_backed_up as $mailbox) {
                                $content .= '<li>' . $mailbox['name'] . ' (' . $mailbox['email_count'] . ' emails, ' . $mailbox['size_mb'] . ' MB)</li>';
                            }
                            $content .= '</ul></div>';
                        }
                        
                        if ($record->error_message) {
                            $content .= '<div><strong>Error:</strong> <span class="text-red-600">' . $record->error_message . '</span></div>';
                        }
                        
                        $content .= '<div><strong>Started:</strong> ' . $record->started_at?->format('Y-m-d H:i:s') . '</div>';
                        $content .= '<div><strong>Completed:</strong> ' . ($record->completed_at?->format('Y-m-d H:i:s') ?? 'In progress') . '</div>';
                        
                        $content .= '</div>';
                        
                        return view('filament.widgets.backup-job-details', ['content' => $content]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelAction(false),
            ])
            ->defaultSort('started_at', 'desc')
            ->striped()
            ->paginated(false);
    }
}