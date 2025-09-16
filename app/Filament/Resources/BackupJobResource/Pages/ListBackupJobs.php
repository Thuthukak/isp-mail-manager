<?php

namespace App\Filament\Resources\BackupJobResource\Pages;

use App\Filament\Resources\BackupJobResource;
use Filament\Resources\Pages\ListRecords;

class ListBackupJobs extends ListRecords
{
    protected static string $resource = BackupJobResource::class;
}