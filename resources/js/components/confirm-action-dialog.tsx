import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { RouteFormDefinition } from '@/wayfinder';

type SupportedMethod = 'post' | 'put' | 'patch' | 'delete';

export default function ConfirmActionDialog({
    form,
    title,
    description,
    triggerLabel,
    confirmLabel,
    triggerVariant = 'destructive',
    confirmVariant = 'destructive',
    triggerSize = 'sm',
    className,
}: {
    form: RouteFormDefinition<SupportedMethod>;
    title: string;
    description: string;
    triggerLabel: string;
    confirmLabel: string;
    triggerVariant?: 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link';
    confirmVariant?: 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link';
    triggerSize?: 'default' | 'sm' | 'lg' | 'icon';
    className?: string;
}) {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    size={triggerSize}
                    variant={triggerVariant}
                    className={className}
                >
                    {triggerLabel}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2 sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setIsOpen(false)}
                    >
                        Cancel
                    </Button>
                    <Form
                        {...form}
                        onSuccess={() => setIsOpen(false)}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant={confirmVariant}
                                disabled={processing}
                            >
                                {confirmLabel}
                            </Button>
                        )}
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
