<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load the NPA/34 watch notes (the notes feed JSON) into the database, where the /sn34 page reads them. Scheduled every
 * minute only where SN34_NOTES_FILE is set (the machine the NPA/34 watch runs on); the deployed dashboard keeps the notes
 * it was given and reads no file.
 */
class Sn34Notes extends Command
{
    protected $signature = 'sn34:notes {file? : the notes feed (default SN34_NOTES_FILE)}';

    protected $description = 'Load the SN34 watch notes from the notes feed into the database';

    public function handle(): int
    {
        $file = (string) ($this->argument('file') ?: config('sn34.notes_file'));
        $feed = $file !== '' && is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($feed)) {
            $this->error("no readable notes feed: '$file'");

            return self::FAILURE;
        }
        // only the parts the page shows (the English rows, family notes, loop stops, the feed time)
        $keep = array_intersect_key($feed, array_flip(['meta', 'notes_en', 'family_notes', 'loop_stops']));
        DB::table('sn34_notes')->updateOrInsert(['name' => 'feed'], ['payload' => json_encode($keep), 'updated_at' => now()]);
        $this->line('sn34 notes loaded: '.count($keep['notes_en']['watch'] ?? []).' watch rows, '.count($keep['notes_en']['discord'] ?? []).' Discord rows');

        return self::SUCCESS;
    }
}
