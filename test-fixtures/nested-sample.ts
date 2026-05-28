// Fichier de test pour get_file_outline — nesting multi-niveaux

export const CONFIG_VERSION = "1.0";

export interface PluginConfig {
  name: string;
  enabled: boolean;
}

export function createRegistry() {
  function validateEntry(name: string): boolean {
    return name.length > 0;
  }

  function buildIndex(entries: string[]) {
    function sortEntries(a: string, b: string): number {
      return a.localeCompare(b);
    }
    return entries.sort(sortEntries);
  }

  return { validateEntry, buildIndex };
}

export class PluginManager {
  private plugins: Map<string, PluginConfig> = new Map();

  register(name: string, config: PluginConfig): void {
    this.plugins.set(name, config);
  }

  unregister(name: string): boolean {
    return this.plugins.delete(name);
  }

  getAll(): PluginConfig[] {
    return Array.from(this.plugins.values());
  }
}

export class EventBus {
  private handlers: Map<string, Function[]> = new Map();

  on(event: string, handler: Function): void {
    const existing = this.handlers.get(event) ?? [];
    this.handlers.set(event, [...existing, handler]);
  }

  off(event: string, handler: Function): void {
    const existing = this.handlers.get(event) ?? [];
    this.handlers.set(event, existing.filter((h) => h !== handler));
  }

  emit(event: string, payload: unknown): void {
    const handlers = this.handlers.get(event) ?? [];
    for (const handler of handlers) {
      handler(payload);
    }
  }
}

export function bootstrap(config: PluginConfig[]) {
  const bus = new EventBus();

  function setupPlugin(plugin: PluginConfig): void {
    bus.emit("plugin:setup", plugin);
  }

  function teardownPlugin(plugin: PluginConfig): void {
    bus.emit("plugin:teardown", plugin);
  }

  config.forEach(setupPlugin);
  return { bus, teardownPlugin };
}
