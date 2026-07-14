<?php

namespace App\Exports;

use App\Models\ReceivedMessage;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class UserReceivedMessagesExport implements FromCollection, WithHeadings, WithMapping
{
    protected $userId;

    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    public function collection()
    {
        return ReceivedMessage::where('user_id', $this->userId)->orderBy('received_at', 'desc')->get();
    }

    public function headings(): array
    {
        return ['الرقم', 'اسم صاحب الرقم', 'الرسالة', 'التاريخ', 'الوقت'];
    }

    public function map($row): array
    {
        return [
            $row->phone,
            $row->name,
            $row->message,
            $row->received_at->format('Y-m-d'),
            $row->received_at->format('H:i:s'),
        ];
    }
}
