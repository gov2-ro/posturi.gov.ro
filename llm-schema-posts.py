import openai
import json
from dotenv import load_dotenv
import os

# read api key fron .env file
load_dotenv()
openai.api_key = os.getenv("OPENAI_API_KEY")

# openai.api_key = ""


# Function to call GPT-4 and generate schema.org/JobPosting
def generate_jobposting_schema(markdown_content):
    # OpenAI GPT-4 API call
    response = openai.ChatCompletion.create(
        model="gpt-4",  # Specify the GPT model here (using GPT-4 as a placeholder)
        messages=[
            {
                "role": "system",
                "content": "You are a helpful assistant that converts job postings in markdown into schema.org/JobPosting JSON format."
            },
            {
                "role": "user",
                "content": f"Here is a job posting in markdown format: \n\n{markdown_content}\n\nConvert this into a schema.org/JobPosting JSON structure."
            }
        ],
        max_tokens=1500,
        temperature=0.3,
    )
    
    # Extract the job posting schema from the response
    generated_schema = response['choices'][0]['message']['content']
    
    try:
        # Attempt to convert the output to JSON
        schema_json = json.loads(generated_schema)
        return schema_json
    except json.JSONDecodeError:
        print("Failed to parse GPT output as JSON.")
        return generated_schema

# Function to read a markdown file and return its content
def read_markdown_file(filepath):
    with open(filepath, 'r') as file:
        content = file.read()
    return content

# Main function
if __name__ == "__main__":
    # Specify the markdown file path
    markdown_file_path = 'path/to/job_posting.md'
    
    # Read markdown content
    markdown_content = read_markdown_file(markdown_file_path)
    
    # Generate JobPosting schema from markdown content
    job_posting_schema = generate_jobposting_schema(markdown_content)
    
    # Output the generated schema
    print("Generated JobPosting schema (JSON):")
    print(json.dumps(job_posting_schema, indent=4))
