import { beforeEach, describe, expect, it, vi } from 'vitest';
import { supabase } from '../../supabase.js';

describe('API compatibility query builder', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
  });

  it('keeps POST semantics for insert().select().single()', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({
      id: 5984,
      status: 'rescheduled',
    }), { status: 201, headers: { 'Content-Type': 'application/json' } }));

    const { data, error } = await supabase
      .from('schedules')
      .insert([{ status: 'rescheduled' }])
      .select('id')
      .single();

    expect(error).toBeNull();
    expect(data.id).toBe(5984);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
  });

  it('supports select().maybeSingle() for idempotent schedule lookup', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({
      data: [{ id: 5984 }],
    }), { status: 200, headers: { 'Content-Type': 'application/json' } }));

    const { data, error } = await supabase
      .from('schedules')
      .select('id')
      .eq('status', 'rescheduled')
      .maybeSingle();

    expect(error).toBeNull();
    expect(data).toEqual({ id: 5984 });
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
  });
});
