import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export default function FormSelect({
    name,
    value,
    onValueChange,
    options,
    placeholder,
    id,
    disabled = false,
}: {
    name: string;
    value: string;
    onValueChange: (value: string) => void;
    options: Array<{
        value: string;
        label: string;
    }>;
    placeholder?: string;
    id?: string;
    disabled?: boolean;
}) {
    return (
        <>
            <input type="hidden" name={name} value={value} />
            <Select
                value={value}
                onValueChange={onValueChange}
                disabled={disabled}
            >
                <SelectTrigger id={id} className="h-10">
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </>
    );
}
