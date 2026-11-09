<?php

namespace App\Console\Commands;

use App\Audit\AuditChain;
use App\Models\AuditLog;
use App\Models\Community;
use App\Tenancy\CurrentCommunity;
use Illuminate\Console\Command;

/**
 * Demostración para la exposición: memoria al recorrer la bitácora cargándola completa
 * (->get()) contra recorrerla por bloques (lazyById, lo que usa AuditChain::verify()).
 */
class DemoMemoria extends Command
{
    protected $signature = 'sinagua:demo-memoria {comunidad? : id de la comunidad (por omisión, la que tenga más registros)}';

    protected $description = 'Compara la memoria de cargar la bitácora completa contra recorrerla por bloques';

    public function handle(CurrentCommunity $context): int
    {
        $id = $this->argument('comunidad') ?? $context->runAsSystem(fn () => AuditLog::query()
            ->whereNotNull('community_id')->selectRaw('community_id, count(*) as total')
            ->groupBy('community_id')->orderByDesc('total')->value('community_id'));
        if ($id === null) {
            $this->error('No hay registros en la bitácora.');

            return self::FAILURE;
        }
        $context->set($id);
        $name = $context->runAsSystem(fn () => Community::find($id)?->name);

        $loaded = $this->measure(fn () => AuditLog::where('community_id', $id)->orderBy('id')->get()->count());
        $streamed = $this->measure(fn () => AuditChain::verify($id)['checked']);

        $this->info("Comunidad: {$name}");
        $this->table(['Forma de recorrer', 'Registros', 'Memoria pico extra', 'Tiempo'], [
            ['->get() (todo en memoria)', $loaded['result'], $this->mb($loaded['peak']), $loaded['ms'].' ms'],
            ['lazyById('.AuditChain::CHUNK.') (por bloques)', $streamed['result'], $this->mb($streamed['peak']), $streamed['ms'].' ms'],
        ]);
        $this->line('Con lazyById la memoria se queda casi igual aunque la bitácora crezca; con ->get() crece con cada registro.');

        return self::SUCCESS;
    }

    /** @return array{result: mixed, peak: int, ms: int} */
    private function measure(callable $fn): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $start = hrtime(true);
        $result = $fn();

        return ['result' => $result, 'peak' => memory_get_peak_usage() - $before, 'ms' => (int) ((hrtime(true) - $start) / 1e6)];
    }

    private function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 2).' MB';
    }
}
