import { describe, expect, it, vi } from 'vitest';
import { shareInFlight } from '../../lib/shareInFlight';

describe('shareInFlight', () => {
  it('joins calls made while a run is in flight, then allows a new run', async () => {
    let finish;
    const fn = vi.fn(() => new Promise((resolve) => { finish = resolve; }));
    const run = shareInFlight(fn);

    const first = run();
    const second = run();
    expect(fn).toHaveBeenCalledTimes(1);
    finish(true);
    expect(await first).toBe(true);
    expect(await second).toBe(true);

    run();
    expect(fn).toHaveBeenCalledTimes(2);
  });

  it('releases the slot after a failure', async () => {
    const fn = vi.fn().mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce(false);
    const run = shareInFlight(fn);
    await expect(run()).rejects.toThrow('network');
    expect(await run()).toBe(false);
  });
});
