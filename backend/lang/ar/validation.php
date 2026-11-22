<?php

// Arabic messages for the rules the store uses; anything else falls back to English.
return [
    'accepted' => 'يجب الموافقة على :attribute.',
    'alpha_dash' => 'يجب أن يحتوي :attribute على أحرف وأرقام وشرطات فقط.',
    'boolean' => 'قيمة :attribute غير صحيحة.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًا صحيحًا.',
    'exists' => ':attribute المحدد غير صالح.',
    'in' => ':attribute المحدد غير صالح.',
    'max' => ['numeric' => 'يجب ألا يزيد :attribute عن :max.', 'string' => 'يجب ألا يزيد :attribute عن :max حرفًا.'],
    'min' => ['numeric' => 'يجب ألا يقل :attribute عن :min.', 'string' => 'يجب ألا يقل :attribute عن :min أحرف.'],
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت :attribute في تسريب بيانات. اختر غيرها.',
    ],
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'size' => ['string' => 'يجب أن يتكون :attribute من :size أحرف.'],
    'string' => 'يجب أن يكون :attribute نصًا.',
    'unique' => ':attribute مستخدم من قبل.',
    'attributes' => [
        'name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور', 'current_password' => 'كلمة المرور الحالية',
        'terms' => 'الشروط', 'accept_terms' => 'شروط البيع', 'code' => 'الرمز', 'subject' => 'الموضوع', 'body' => 'الرسالة',
        'billing_name' => 'الاسم الكامل', 'billing_country' => 'الدولة', 'billing_vat_number' => 'الرقم الضريبي', 'category' => 'الموضوع',
    ],
];
