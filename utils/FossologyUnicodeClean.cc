/*
 * Copyright (C) 2019, Siemens AG
 * Author: Gaurav Mishra <mishra.gaurav@siemens.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

#include "FossologyUnicodeClean.hpp"

using namespace std;

/**
 * Destructor to flush the streams and close any open files.
 */
FossologyUnicodeClean::~FossologyUnicodeClean()
{
  this->flush();
  if (this->destinationFile.is_open())
  {
    this->destinationFile.close();
  }
  if (this->sourceFile.is_open())
  {
    this->sourceFile.close();
  }
}

/**
 * Constructor to open the input and output files (if passed).
 * Also reserve the buffer in internal vector
 * @param source      Source file path (STDIN if empty)
 * @param destination Destination file path (STDOUT if empty)
 */
FossologyUnicodeClean::FossologyUnicodeClean(string &source,
  string &destination) : sourceFile(NULL), destinationFile(NULL),
      bufferSize (0), stopRead(false)
{
  if ((!source.empty() && !destination.empty()) && (source == destination))
  {
    cerr << "Input and Output files can not be same.\n";
    cerr << "Input: " << source << "\nOutput: " << destination;
    cerr << " passed" << endl;
    exit(-3);
  }
  if (!source.empty())
  {
    sourceFile.open(source, ios::in | ios::binary);
    if (sourceFile.fail())
    {
      cerr << "Unable to open " << source << endl;
      cerr << "Error: " << strerror(errno) << endl;
      exit(-1);
    }
  }
  if (!destination.empty())
  {
    destinationFile.open(destination, ios::out | ios::binary | ios::trunc);
    if (destinationFile.fail())
    {
      cerr << "Unable to open " << destination << endl;
      cerr << "Error: " << strerror(errno) << endl;
      exit(-2);
    }
  }
  this->buffer.reserve(MAX_BUFFER_LEN);
}

/**
 * Remove non UTF-8 characters from input and return icu::UnicodeString
 * @param input Raw input
 * @return UTF-8 valid string
 */
const icu::UnicodeString FossologyUnicodeClean::removeNonUtf(const string &input)
{
  int len = input.length();
  const unsigned char *in = reinterpret_cast<const unsigned char *>(input.c_str());

  icu::UnicodeString out;
  for (int i = 0; i < len;) {
    UChar32 uniChar;
    int lastPos = i;
    U8_NEXT(in, i, len, uniChar);
    if (uniChar > 0) {
      out.append(uniChar);
    } else {
      i = lastPos;
      U16_NEXT(in, i, len, uniChar);
      if (U_IS_UNICODE_CHAR(uniChar) && uniChar > 0) {
        out.append(uniChar);
      }
    }
  }
  return out;
}

/**
 * Start the process to read from file/stream -> remove invalid chars -> print
 * to file/stream.
 */
void FossologyUnicodeClean::startConvert()
{
  string input;
  input = this->dirtyRead();
  while (!this->stopRead)
  {
    icu::UnicodeString output = this->removeNonUtf(input);
    this->write(output);
    input = this->dirtyRead();
  }
  this->flush();
}

/**
 * Read raw input from file or STDIN
 * @return Raw string with MAX_LINE_READ characters.
 */
const string FossologyUnicodeClean::dirtyRead()
{
  string input;
  if (sourceFile.eof() || cin.eof())
  {
    this->stopRead = true;
    return "";
  }
  if (sourceFile && sourceFile.is_open())
  {
    std::getline(sourceFile, input, '\n');
  }
  else
  {
    std::getline(cin, input, '\n');
  }
  return input;
}

/**
 * @brief Write the string to file/stream.
 *
 * * If the buffer is not filled, append to the buffer vector.
 * * If the buffer is filled, call flush.
 * @param output
 */
void FossologyUnicodeClean::write(const icu::UnicodeString &output)
{
  this->buffer.push_back(output);
  this->bufferSize++;
  if (this->bufferSize == MAX_BUFFER_LEN)
  {
    this->flush();
  }
}

/**
 * @brief Flush the buffers and reset the internal buffer
 *
 * Print the content of internal buffer to appropriate streams and flush them.
 * Then clear the internal buffer and reset the size.
 */
void FossologyUnicodeClean::flush()
{
  if (destinationFile && destinationFile.is_open())
  {
    for (size_t i = 0; i < this->buffer.size(); i++)
    {
      string temp;
      buffer[i].toUTF8String(temp);
      destinationFile << temp << "\n";
    }
  }
  else
  {
    for (size_t i = 0; i < this->buffer.size(); i++)
    {
      string temp;
      buffer[i].toUTF8String(temp);
      cout << temp << "\n";
    }
  }
  buffer.clear();
  bufferSize = 0;
}

/**
 * Parse the CLI options for the program.
 * @param argc        From main()
 * @param argv        From main()
 * @param[out] input  Input file path string (empty if not sent)
 * @param[out] output Output file path string (empty if not sent)
 * @return True if options parsed successfully, false otherwise
 */
bool parseCliOptions(int argc, char **argv, string &input, string &output)
{
  boost::program_options::options_description desc("fo_unicode_clean "
    ": recognized options");
  desc.add_options()
  (
    "help,h", "shows help"
  )
  (
    "input,i",
    boost::program_options::value<string>(),
    "file to read"
  )
  (
    "output,o",
    boost::program_options::value<string>(),
    "output file"
  )
  ;

  boost::program_options::variables_map vm;

  try
  {
    boost::program_options::store(
      boost::program_options::command_line_parser(argc,
        argv).options(desc).run(), vm);

    if (vm.count("help") > 0)
    {
      cout << desc << endl;
      cout << "If no input passed, read from STDIN." << endl;
      cout << "If no output passed, print to STDOUT." << endl;
      exit(0);
    }

    if (vm.count("input"))
    {
      input = vm["input"].as<string>();
    }
    if (vm.count("output"))
    {
      output = vm["output"].as<string>();
    }
    return true;
  }
  catch (boost::bad_any_cast&)
  {
    cout << "wrong parameter type" << endl;
    cout << desc << endl;
    return false;
  }
  catch (boost::program_options::error&)
  {
    cout << "wrong command line arguments" << endl;
    cout << desc << endl;
    return false;
  }
}

int main(int argc, char **argv)
{
  string input, output;
  if (parseCliOptions(argc, argv, input, output))
  {
    FossologyUnicodeClean obj(input, output);
    obj.startConvert();
    return 0;
  }
  return -4;
}
