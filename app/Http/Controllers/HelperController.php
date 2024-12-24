<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HelperController extends Controller
{
    /**
     * تابع کمکی برای نرمال‌سازی متن
     */
    public function normalizeText($text)
    {
        $mapping = [
            'ي' => 'ی',
            'ك' => 'ک',
            'أ' => 'ا',
            'إ' => 'ا',
            'ؤ' => 'و',
            'ئ' => 'ی',
            'ۀ' => 'ه',
            'هٔ' => 'ه',
            'ة' => 'ه',
            '‌' => ' ', // نیم‌فاصله به فاصله کامل
            'ـ' => '',  // حذف خط تزیینی
        ];
        // حذف کاراکترهای کنترلی (مانند \r، \n، \t)
        $text = preg_replace('/[\r\n\t]+/', ' ', $text);

        // حذف فاصله‌های اضافی
        $text = preg_replace('/\s+/', ' ', $text);

        // جایگزینی کاراکترها بر اساس نگاشت
        $text = strtr($text, $mapping);
        // حذف فاصله‌های اضافی از ابتدا و انتهای متن
        return trim($text);
    }

    public function extractUrl($content)
    {
        $lines = explode(PHP_EOL, $content);
        foreach (array_slice($lines, 0, 3) as $line) {
            if (preg_match('/https?:\/\/[^\s]+/', $line, $matches)) {
                return $matches[0];
            }
        }
        return '';
    }


    /**
     * پردازش محتوای HTML برای استخراج عنوان و متن اصلی
     */
    public function parseHtml($content)
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML($content);

        $title = $doc->getElementsByTagName('title')->item(0)->textContent ?? null;

        // استفاده از Xpath برای حذف استایل‌ها، اسکریپت‌ها و دیگر تگ‌های اضافی
        $xpath = new \DOMXPath($doc);
        $body = $xpath->query('//body')->item(0);

        // حذف تگ‌های style و script از body
        foreach ($body->getElementsByTagName('style') as $style) {
            $style->parentNode->removeChild($style);
        }

        foreach ($body->getElementsByTagName('script') as $script) {
            $script->parentNode->removeChild($script);
        }

        // دریافت تنها متن از body
        $bodyText = $body->textContent ?? null;

        // نرمال‌سازی متن
        $title = $this->normalizeText($title);
        $bodyText = $this->normalizeText($bodyText);

        return [
            'title' => $title,
            'body' => $bodyText,
        ];
    }
}
