import { useForm } from '@inertiajs/vue3'

type FormState = {
  title: string
  views: number | null
  is_active: boolean
  meta: string
}

const form = useForm<FormState>({
  title: '',
  views: null,
  is_active: true,
  meta: '',
})

export const titleError: string | undefined = form.errors.title

form.transform((data) => ({ ...data, meta: JSON.parse(data.meta) }))

form.clearErrors('meta')

form.setError('meta', 'The meta field must be valid JSON.')

form.post('/posts')

// @ts-expect-error errors are keyed by the form's own fields
void form.errors.missing

// @ts-expect-error setError accepts only the form's own fields
form.setError('missing', 'Unknown field.')

// @ts-expect-error setError takes a string message
form.setError('meta', 42)
