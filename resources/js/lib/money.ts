export function penceToPoundsInput(pence: number): string {
    const major = Math.floor(Math.abs(pence) / 100);
    const minor = Math.abs(pence) % 100;

    return `${major}.${minor.toString().padStart(2, '0')}`;
}

export function poundsInputToPence(value: string): number {
    const cleaned = value.trim();
    const match = cleaned.match(/^(\d+)(?:\.(\d{0,2}))?$/);

    if (!match) {
        return 0;
    }

    const major = parseInt(match[1], 10);
    const minor = parseInt((match[2] ?? '').padEnd(2, '0').slice(0, 2) || '0', 10);

    return major * 100 + minor;
}
