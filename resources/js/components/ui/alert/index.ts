import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Alert } from "./Alert.vue"
export { default as AlertDescription } from "./AlertDescription.vue"
export { default as AlertTitle } from "./AlertTitle.vue"

export const alertVariants = cva(
  "relative w-full rounded-lg border px-4 py-3 text-sm grid has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] grid-cols-[0_1fr] has-[>svg]:gap-x-3 gap-y-0.5 items-start [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current",
  {
    variants: {
      variant: {
        default: "bg-card text-card-foreground",
        info:
          "border-blue-500/30 bg-blue-500/10 text-blue-900 [&>svg]:text-blue-700 *:data-[slot=alert-description]:text-blue-800/90 dark:text-blue-100 dark:[&>svg]:text-blue-300 dark:*:data-[slot=alert-description]:text-blue-200/90",
        success:
          "border-emerald-500/30 bg-emerald-500/10 text-emerald-900 [&>svg]:text-emerald-700 *:data-[slot=alert-description]:text-emerald-800/90 dark:text-emerald-100 dark:[&>svg]:text-emerald-300 dark:*:data-[slot=alert-description]:text-emerald-200/90",
        warning:
          "border-amber-500/30 bg-amber-500/10 text-amber-950 [&>svg]:text-amber-700 *:data-[slot=alert-description]:text-amber-900/90 dark:text-amber-100 dark:[&>svg]:text-amber-300 dark:*:data-[slot=alert-description]:text-amber-200/90",
        destructive:
          "border-destructive/30 bg-destructive/10 text-destructive [&>svg]:text-current *:data-[slot=alert-description]:text-destructive/90",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)

export type AlertVariants = VariantProps<typeof alertVariants>
