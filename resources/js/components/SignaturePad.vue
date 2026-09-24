<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

const model = defineModel<string | null>({ default: null });
const canvas = ref<HTMLCanvasElement>();
let drawing = false;

function point(event: PointerEvent, element: HTMLCanvasElement) {
    const bounds = element.getBoundingClientRect();

    return {
        x: ((event.clientX - bounds.left) / bounds.width) * element.width,
        y: ((event.clientY - bounds.top) / bounds.height) * element.height,
    };
}

function start(event: PointerEvent) {
    const element = event.currentTarget as HTMLCanvasElement;
    const context = element.getContext('2d');
    if (!context) return;

    drawing = true;
    element.setPointerCapture(event.pointerId);
    const current = point(event, element);
    context.beginPath();
    context.moveTo(current.x, current.y);
    context.strokeStyle = '#111827';
    context.lineWidth = 5;
    context.lineCap = 'round';
    context.lineJoin = 'round';
}

function draw(event: PointerEvent) {
    if (!drawing) return;
    const element = event.currentTarget as HTMLCanvasElement;
    const context = element.getContext('2d');
    if (!context) return;

    const current = point(event, element);
    context.lineTo(current.x, current.y);
    context.stroke();
}

function finish(event: PointerEvent) {
    if (!drawing) return;
    const element = event.currentTarget as HTMLCanvasElement;
    drawing = false;
    if (element.hasPointerCapture(event.pointerId)) {
        element.releasePointerCapture(event.pointerId);
    }
    model.value = element.toDataURL('image/png');
}

function clear() {
    const element = canvas.value;
    element?.getContext('2d')?.clearRect(0, 0, element.width, element.height);
    model.value = null;
}
</script>

<template>
    <div class="space-y-2">
        <canvas
            ref="canvas"
            width="900"
            height="260"
            class="h-40 w-full touch-none rounded-md border bg-white"
            aria-label="Feld zum Zeichnen der Unterschrift"
            @pointerdown="start"
            @pointermove="draw"
            @pointerup="finish"
            @pointercancel="finish"
        />
        <div class="flex items-center justify-between gap-3">
            <p class="text-xs text-muted-foreground">
                Mit Maus, Stift oder Finger im Feld unterschreiben.
            </p>
            <Button type="button" variant="outline" size="sm" @click="clear">
                Leeren
            </Button>
        </div>
    </div>
</template>
